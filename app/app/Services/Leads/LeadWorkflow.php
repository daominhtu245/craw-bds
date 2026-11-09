<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\RawItemStatus;
use App\Jobs\ClassifyItemJob;
use App\Models\KnownAgent;
use App\Models\Lead;
use App\Models\RawItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sales actions on a lead (SPEC 14). Every change writes a lead_activity.
 */
class LeadWorkflow
{
    public function claim(Lead $lead, User $user): Lead
    {
        return DB::transaction(function () use ($lead, $user) {
            $from = $lead->status;
            $lead->assigned_to = $user->id;
            if ($lead->status === LeadStatus::New) {
                $lead->status = LeadStatus::Assigned;
            }
            $lead->save();

            $this->log($lead, $user, ActivityType::StatusChange, "{$user->name} đã nhận lead.", [
                'from' => $from->value,
                'to' => $lead->status->value,
                'assigned_to' => $user->id,
            ]);

            return $lead;
        });
    }

    /**
     * @throws ValidationException when lost/invalid is chosen without a note.
     */
    public function changeStatus(Lead $lead, LeadStatus $to, User $user, ?string $note = null): Lead
    {
        $note = trim((string) $note);

        if ($to->requiresNote() && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'Bắt buộc nhập ghi chú khi chuyển sang trạng thái "'.$to->getLabel().'".',
            ]);
        }

        if ($lead->status === $to && $note === '') {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $to, $user, $note) {
            $from = $lead->status;
            $lead->status = $to;
            if ($lead->assigned_to === null && ! in_array($to, [LeadStatus::New, LeadStatus::Invalid, LeadStatus::Archived], true)) {
                $lead->assigned_to = $user->id;
            }
            $lead->save();

            $body = 'Trạng thái: '.$from->getLabel().' → '.$to->getLabel().($note !== '' ? ". Ghi chú: {$note}" : '');
            $this->log($lead, $user, ActivityType::StatusChange, $body, ['from' => $from->value, 'to' => $to->value, 'note' => $note ?: null]);

            return $lead;
        });
    }

    public function addNote(Lead $lead, User $user, string $note): void
    {
        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Ghi chú không được để trống.']);
        }

        $this->log($lead, $user, ActivityType::Note, $note);
    }

    /** Sale copied a draft and replied by hand at the original post. */
    public function recordReplyCopied(Lead $lead, User $user, string $variant): void
    {
        $this->log($lead, $user, ActivityType::ReplySent, "Đã copy bản nháp ({$variant}) để trả lời thủ công.", ['variant' => $variant]);
    }

    /**
     * "Là môi giới": add the author to known_agents and invalidate the lead.
     */
    public function markAsAgent(Lead $lead, User $user): void
    {
        $lead->loadMissing('rawItem');
        $key = $lead->rawItem?->author_key;

        DB::transaction(function () use ($lead, $user, $key) {
            if ($key !== null) {
                KnownAgent::query()->firstOrCreate(
                    ['author_key' => $key],
                    ['reason' => "manual: lead #{$lead->id}", 'created_by' => (string) $user->id],
                );
            }

            $this->changeStatus($lead, LeadStatus::Invalid, $user, 'Là môi giới');
        });
    }

    /** "Phân loại lại" on a raw item. */
    public function reclassify(RawItem $item): void
    {
        $item->forceFill(['status' => RawItemStatus::Queued, 'filter_reason' => null])->save();
        ClassifyItemJob::dispatch($item->id);
    }

    /** @param array<string, mixed> $meta */
    private function log(Lead $lead, ?User $user, ActivityType $type, string $body, array $meta = []): void
    {
        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => $type,
            'body' => $body,
            'meta' => $meta ?: null,
        ]);
    }
}
