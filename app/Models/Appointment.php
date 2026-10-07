<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'user_id', 'title', 'description', 'appointment_date', 'appointment_time', 'location', 'status', 'label', 'notes'])]
class Appointment extends Model
{
    use Auditable, HasFactory;

    public const STATUSES = ['scheduled', 'completed', 'cancelled', 'rescheduled'];

    /** Calendar colour labels; their names are App\Services\CalendarLabels. */
    public const LABELS = ['green', 'blue', 'yellow', 'red'];

    protected function casts(): array
    {
        return ['appointment_date' => 'date:Y-m-d'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
