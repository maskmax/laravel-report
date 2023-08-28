<?php

namespace RK\Report\app\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use vendor\Report\app\Models\Report;

class QueryController
{
    //
    private $chunk = 1000;
    private $startOffset = null;
    private $offset = null;
    private $limit = null;

    public function __construct()
    {
        $this->startOffset = $this->offset = request()->get('offset', 0);
        $this->limit = request()->get('limit', null);
    }

    private function getChunkData($query, $columns)
    {
        if (isset($this->limit) && ($this->offset - $this->startOffset) >= $this->limit) {
            return null;
        }

        $limit = $this->chunk;
        if (isset($this->limit)) {
            if ($this->limit / $this->chunk >= 1) {
                $limit = $this->chunk;
            } else {
                $limit = $this->startOffset + ($this->limit - $this->offset);
            }
        }

        $data = DB::select('
            with cte as (
                ' . $query . '
            )
            select ' . (isset($columns) ? join(', ', $columns) : '*') . ' from cte
            ORDER BY 1
            OFFSET ' . $this->offset . ' ROWS
            FETCH NEXT ' . $limit . ' ROWS ONLY
        ');

        $data = collect($data)
            ->map(function ($x) {
                return (array)$x;
            })
            ->toArray();

        $this->offset += $this->chunk;

        return $data;
    }

    private function getData($query, $columns)
    {
        $data = DB::select('
            with cte as (
                ' . $query . '
            )
            select ' . (isset($columns) ? join(', ', $columns) : '*') . ' from cte
            ORDER BY id
            OFFSET ' . $this->offset . ' ROWS
            FETCH NEXT ' . $this->chunk . ' ROWS ONLY
        ');

        $data = collect($data)
            ->map(function ($x) {
                return (array)$x;
            })
            ->toArray();

        $this->offset += $this->chunk;

        return $data;
    }

    private function getParameters($parameters)
    {
        $otherParameters = [];
        $parameterValidate = collect($parameters)->map(function ($parameter, $field) use (&$otherParameters) {
            $parameterValidate = collect($parameter)->filter(function ($value, $rule) use ($field, &$otherParameters) {
                if (in_array($rule, ['required', 'in'])) {
                    return true;
                } else {
                    $otherParameters[$field][$rule] = $value;
                }
            });
            return $parameterValidate->flatMap(function ($value, $rule) {
                if (in_array($rule, ['required'])) {
                    return [$rule];
                } else {
                    return [$rule . ':' . $value];
                }
            });
        });
        return [$parameterValidate, $otherParameters];
    }

    public function execQuery($query, $parameters, $columns)
    {
        $request = request();
        $columns = json_decode($columns, true);
        $columnsKey = array_keys($columns);

        $parameters = json_decode($parameters, true);
        [$parameterValidate, $otherParameters] = $this->getParameters($parameters);

        $input = $request->validate([
                'type' => 'required|in:json,xlsx,csv',
                'offset' => 'nullable|int',
                'limit' => 'nullable|int',
            ]
            + $parameterValidate->toArray()
        );


        foreach ($parameters as $key => $parameter) {
            $query = str_replace(
                '{' . $key . '}',
                $request->get($key, $parameter['default'] ?? null),
                $query
            );
        }


        if ($request->get('type') == 'xlsx') {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $result = $this->getData($query, $columnsKey);
            array_unshift($result, $columns);
            $sheet->fromArray($result);
            $response = '';

            $response = response()->streamDownload(function () use ($spreadsheet) {
                $writer = new Xlsx($spreadsheet);
                $writer->save('files/report-' . date('Y-m-d H-i-s') . '.xlsx');
            });

            return $response;
        } elseif ($request->get('type') == 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=report-' . date('Y-m-d H:i:s') . '.csv');

            set_time_limit(0);
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'w');
            $firstLoop = true;
            while ($result = $this->getChunkData($query, $columnsKey)) {
                if ($firstLoop) {
                    array_unshift($result, $columns);
                    $firstLoop = !$firstLoop;
                }
                foreach ($result as $row) {
                    fputcsv($output, $row);
                }
            }
            fclose($output);
        } else {
            header('Content-Type: application/json');

            set_time_limit(0);

            $output = fopen('php://output', 'w');
            fputs($output, '[');
            $firstLoop = true;
            while ($result = $this->getChunkData($query, $columnsKey)) {
                if (!$firstLoop) {
                    fputs($output, ',');
                }
                if ($firstLoop) {
                    array_unshift($result, $columns);
                    $firstLoop = false;
                }

                foreach ($result as $key => $row) {
                    fputs($output, json_encode($row));
                    if (sizeof($result) - 1 > $key) {
                        fputs($output, ',');
                    }
                }
            }
            fputs($output, ']');
            fclose($output);
        }
    }
}
