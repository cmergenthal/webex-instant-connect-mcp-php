<?php

declare(strict_types=1);

namespace WebexInstantConnectMcp;

final class MeetingInviteService
{
    public function __construct(
        private readonly InstantConnectClient $meetingClient,
        private readonly WorkItemClient $workItemClient,
        private readonly WebexConnectSmsClient $smsClient,
        private readonly IdempotencyStore $idempotencyStore
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function createAndSend(array $arguments): array
    {
        $phone = trim((string) ($arguments['caller_phone_number'] ?? ''));
        $interactionId = trim((string) ($arguments['interaction_id'] ?? ''));
        // Version the key so results cached before work-item creation was added are never reused.
        $idempotencyKey = $interactionId === '' ? null : 'work-item-v1|' . $interactionId . '|' . $phone;

        if ($idempotencyKey === null) {
            return $this->performCreateAndSend($arguments, $phone, $interactionId);
        }

        return $this->idempotencyStore->once(
            $idempotencyKey,
            fn (): array => $this->performCreateAndSend($arguments, $phone, $interactionId)
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function performCreateAndSend(array $arguments, string $phone, string $interactionId): array
    {

        $requestedSubject = trim((string) ($arguments['meeting_subject'] ?? 'Webex Instant Connect'));
        $uniqueSuffix = $interactionId !== '' ? substr(hash('sha256', $interactionId), 0, 10) : bin2hex(random_bytes(5));
        $meetingSubject = $requestedSubject . ' - ' . $uniqueSuffix;
        $expirationMinutes = (int) ($arguments['expiration_minutes'] ?? 30);
        $meeting = $this->meetingClient->createMeeting($meetingSubject, $expirationMinutes);

        try {
            $workItem = $this->workItemClient->create($meeting['host_url']);
        } catch (InstantConnectException $exception) {
            return array_merge($meeting, [
                'caller_phone_number' => $phone,
                'work_item_created' => false,
                'work_item_id' => null,
                'work_item_error' => $exception->getMessage(),
                'sms_sent' => false,
                'sms_message_id' => null,
                'sms_error' => 'SMS was not attempted because work-item creation failed.',
                'reused' => false,
            ]);
        }

        try {
            $sms = $this->smsClient->send($phone, $meeting['guest_url']);
            $result = array_merge($meeting, [
                'caller_phone_number' => $phone,
                'work_item_created' => true,
                'work_item_id' => $workItem['work_item_id'],
                'sms_sent' => true,
                'sms_message_id' => $sms['message_id'],
                'reused' => false,
            ]);
        } catch (InstantConnectException $exception) {
            return array_merge($meeting, [
                'caller_phone_number' => $phone,
                'work_item_created' => true,
                'work_item_id' => $workItem['work_item_id'],
                'sms_sent' => false,
                'sms_message_id' => null,
                'sms_error' => $exception->getMessage(),
                'reused' => false,
            ]);
        }

        return $result;
    }
}
