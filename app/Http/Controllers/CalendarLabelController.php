<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Services\CalendarLabels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Names of the appointment colour labels (green, blue, yellow, red). */
class CalendarLabelController extends Controller
{
    public function __construct(private CalendarLabels $labels) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->labels->list()]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (Appointment::LABELS as $colour) {
            $rules["labels.{$colour}"] = ['required', 'string', 'max:40'];
        }
        $data = $request->validate(['labels' => ['required', 'array']] + $rules, [
            'labels.*.required' => 'Give each colour a name.',
        ]);

        $this->labels->update($data['labels'], $request->user());

        return $this->index();
    }
}
