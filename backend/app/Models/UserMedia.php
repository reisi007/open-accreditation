<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-uploaded photo/attachment stored on the private (auth-gated) disk.
 * The stored `path` is never served directly; it is streamed through the
 * authenticated delivery endpoint only.
 */
#[Fillable(['user_id', 'type', 'path', 'mime', 'size', 'original_name'])]
class UserMedia extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The authenticated delivery URL for this file.
     */
    public function url(): string
    {
        return route('api.user.media.show', ['media' => $this->id]);
    }
}
