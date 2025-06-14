<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Exception;
use Illuminate\Http\Request;
use League\Config\Exception\ValidationException;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $tasks = Task::all();
        return response()->json($tasks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->all();

        if ($response = $this->validateTaskData($data)) return $response;

        $task = Task::create($data);

        return response()->json([
            'message' => 'Task created successfully',
            'task' => $task,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Task not found.'
            ], 404);
        }

        $data = $request->all();

        if ($response = $this->validateTaskData($data)) return $response;

        $task->update($data);

        return response()->json([
            'message' => 'Task updated successfully.',
            'task' => $task,
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Task not found.'
            ], 404);
        }

        $task->delete();

        return response()->json([
            'message' => 'Task with id ' . $id . ' deleted successfully.'
        ], 200);
    }

    protected function validateTaskData(array $data)
    {
        $errors = [];

        foreach ($data as $field => $value) {
            switch ($field) {
                case 'title':
                    if (empty($value) || !is_string($value)) {
                        $errors[$field][] = 'Title is required and must be a string.';
                    }
                    break;

                case 'description':
                    if (!is_null($value) && !is_string($value)) {
                        $errors[$field][] = 'Description must be a string.';
                    }
                    break;

                case 'status':
                    $allowedStatus = ['pending', 'in_progress', 'completed'];
                    if (empty($value) || !in_array($value, $allowedStatus)) {
                        $errors[$field][] = 'Status must be one of: ' . implode(', ', $allowedStatus);
                    }
                    break;

                case 'priority':
                    $allowedPriority = ['low', 'medium', 'high'];
                    if (empty($value) || !in_array($value, $allowedPriority)) {
                        $errors[$field][] = 'Priority must be one of: ' . implode(', ', $allowedPriority);
                    }
                    break;

                case 'due_date':
                    if (!empty($value) && !strtotime($value)) {
                        $errors[$field][] = 'Due date must be a valid date.';
                    }
                    break;
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $errors,
            ], 422);
        }

        return null;
    }
}
