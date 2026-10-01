<?php
/**
 * UniGo - Telephony channel contracts.
 *
 * UniGo is designed for people without smartphones, so the USSD / SMS /
 * call-centre channels are first-class concepts in the domain model. This
 * version ships the INTERFACES and a recording gateway so the architecture can
 * be reviewed and tested, but there is deliberately no fake USSD provider:
 *
 *   - No telco aggregator is contacted.
 *   - Nothing here can push a USSD code to a handset.
 *   - Every request is logged with provider = 'none' so it is impossible to
 *     mistake a captured payload for a real USSD session.
 *
 * Wiring a real provider later = implementing these interfaces against the
 * aggregator's API and registering it in ChannelService.
 */

declare(strict_types=1);

namespace App\Services;

interface UssdGatewayInterface
{
    public function name(): string;

    /** True only when a real telco aggregator is configured. */
    public function isLive(): bool;

    /**
     * Handle an inbound USSD request.
     *
     * @param array{session_id:string,phone:string,dial:string} $request
     * @return array{response:string,menu?:array<int,string>,session_id:string,state:string}
     */
    public function handleRequest(array $request): array;

    /** Close a session after a timeout. */
    public function closeSession(string $sessionId): void;
}

interface SmsGatewayInterface
{
    public function name(): string;

    public function isLive(): bool;

    /**
     * @param array{to:string,message:string,reference?:string} $request
     * @return array{accepted:bool,provider_reference:string,message:string}
     */
    public function send(array $request): array;
}

interface CallCentreGatewayInterface
{
    public function name(): string;

    public function isLive(): bool;

    /**
     * Log a call-centre assisted booking (agent takes the details by phone).
     *
     * @param array{phone:string,agent:string,trip_id:int,seat_number:string,notes?:string} $request
     * @return array{accepted:bool,reference:string}
     */
    public function registerAssistedBooking(array $request): array;
}

/**
 * Recording gateway: stores the intent, sends nothing.
 */
final class InertChannelGateway implements UssdGatewayInterface, SmsGatewayInterface, CallCentreGatewayInterface
{
    public function __construct(private string $channel)
    {
    }

    public function name(): string
    {
        return 'inert-' . $this->channel;
    }

    public function isLive(): bool
    {
        return false;
    }

    public function handleRequest(array $request): array
    {
        $this->log('ussd_request', $request);
        return [
            'response'   => 'UniGo USSD is not connected in this deployment. Use the web app or call the support line.',
            'session_id' => (string) ($request['session_id'] ?? ''),
            'state'      => 'terminated',
        ];
    }

    public function closeSession(string $sessionId): void
    {
        $this->log('ussd_close', ['session_id' => $sessionId]);
    }

    public function send(array $request): array
    {
        $this->log('sms_send', $request);
        return [
            'accepted'           => false,
            'provider_reference' => '',
            'message'            => 'No SMS gateway is configured. The message was recorded locally for review only.',
        ];
    }

    public function registerAssistedBooking(array $request): array
    {
        $this->log('call_centre_booking', $request);
        return [
            'accepted'  => false,
            'reference' => '',
        ];
    }

    private function log(string $event, array $payload): void
    {
        \App\Core\Database::instance()->insert('channel_messages', [
            'channel'       => $this->channel,
            'direction'     => 'outbound',
            'event_type'    => $event,
            'phone_masked'  => substr((string) ($payload['phone'] ?? $payload['to'] ?? ''), -4),
            'payload'       => json_encode($payload),
            'provider'      => 'none',
            'is_simulated'  => 1,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}

/**
 * Facade that decides which gateway handles a channel.
 */
final class ChannelService
{
    /** Menu definition for the future USSD flow (documentation + UI preview). */
    public const USSD_MENU = [
        '1' => 'Book a seat',
        '2' => 'Track my trip',
        '3' => 'My bookings',
        '4' => 'Check fare',
        '5' => 'Send SOS',
        '0' => 'Help',
    ];

    public static function ussd(): UssdGatewayInterface
    {
        // Replace with: new AfricasTalkingUssdGateway(...) when contracted.
        return new InertChannelGateway('ussd');
    }

    public static function sms(): SmsGatewayInterface
    {
        // Replace with: new BulkSmsGateway(...) when contracted.
        return new InertChannelGateway('sms');
    }

    public static function callCentre(): CallCentreGatewayInterface
    {
        return new InertChannelGateway('call_centre');
    }

    /**
     * Entry point for an inbound USSD webhook. Since no gateway is live the
     * request is only acknowledged and logged.
     */
    public static function handleUssdRequest(array $request): array
    {
        return self::ussd()->handleRequest($request);
    }

    /**
     * Queue an outbound SMS. Used today only for booking confirmations on
     * channels where the sender accepts email links, and for logging.
     *
     * @return array{queued:bool,message:string}
     */
    public static function notifySms(int $userId, string $message): array
    {
        $phone = \App\Core\Database::instance()->value('SELECT phone FROM users WHERE id = ?', [$userId]);
        if (!$phone) {
            return ['queued' => false, 'message' => 'This user has no phone number on file.'];
        }
        $result = self::sms()->send(['to' => $phone, 'message' => $message, 'reference' => (string) $userId]);
        return ['queued' => (bool) $result['accepted'], 'message' => (string) $result['message']];
    }
}
