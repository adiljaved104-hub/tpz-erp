<?php

namespace App\Models;

use App\Enums\NoticeAudienceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class HrNotice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'audience_type' => NoticeAudienceType::class,
            'published_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'acknowledgment_required' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (HrNotice $notice): void {
            $material = ['notice_category_id', 'notice_template_id', 'title', 'content', 'audience_type', 'team_id', 'priority', 'published_at', 'expires_at', 'acknowledgment_required', 'published_by_user_id'];
            if ($notice->isDirty($material) && $notice->acknowledgments()->whereNotNull('acknowledged_at')->exists()) {
                throw new LogicException('Acknowledged Notice content is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Notice history cannot be hard-deleted.'));
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(NoticeCategory::class, 'notice_category_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NoticeTemplate::class, 'notice_template_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(HrNoticeRecipient::class);
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(HrAcknowledgment::class);
    }
}
