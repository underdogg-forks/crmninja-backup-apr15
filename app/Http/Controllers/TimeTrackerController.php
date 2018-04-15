<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use Illuminate\Http\Request;

class TimeTrackerController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $account = $user->account;
        if (!$account->hasFeature(FEATURE_TASKS)) {
            return trans('texts.tasks_not_enabled');
        }
        $data = [
          'title' => trans('texts.time_tracker'),
          'tasks' => Task::scope()->with('project', 'client.contacts', 'task_status')->whereNull('invoice_id')->get(),
          'clients' => Client::scope()->with('contacts')->orderBy('name')->get(),
          'projects' => Project::scope()->with('client.contacts')->orderBy('name')->get(),
          'statuses' => TaskStatus::scope()->orderBy('sort_order')->get(),
          'account' => $account,
        ];
        return view('tasks.time_tracker', $data);
    }
}
