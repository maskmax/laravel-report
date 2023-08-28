<?php

namespace RK\Report\app\Http\Controllers\Api;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use RK\Report\app\Models\Report;

class ReportController
{
    //
    public function index()
    {
        return response(Report::paginate(100), Response::HTTP_OK); // pagination
    }

    public function store(Request $request)
    {
        $input = $request->validate([
            'name' => 'required',
            'title' => 'required',
            'query' => 'required',
            'parameters' => 'nullable',
            'columns' => 'nullable',
        ]);

        if (isset($input['columns'])) {
            $input['columns'] = json_encode($input['columns']);
        }
        if (isset($input['parameters'])) {
            $input['parameters'] = json_encode($input['parameters']);
        }

        $report = Report::create($input);

        return response(['id' => $report['id'], 'name' => $report['name']], Response::HTTP_OK);
    }

    public function show($report)
    {
        /*DB::listen(function ($query) {
            logger()->info($query->sql);
        });*/
        $report = Report::where('id', $report)
            ->orWhere('name', $report)
            ->first();

        if (!isset($report)) {
            throw (new ModelNotFoundException)->setModel(
                get_class($report), $report
            );
        }

        $quertController = new QueryController();
        return $quertController->execQuery($report->query, $report->parameters, $report->columns);
    }

    public function destroy($report)
    {
        Report::where('id', $report)->delete();
        return response(true, Response::HTTP_OK); // pagination
    }
}
