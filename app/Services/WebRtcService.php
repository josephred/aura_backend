<?php

namespace App\Services;

/**
 * ICE server configuration for the self-hosted WebRTC video calls.
 *
 * STUN only discovers each peer's public address; media flows directly
 * between the participants (DTLS-SRTP encrypted). A TURN relay — coturn
 * on the production server — is used only when networks block the
 * direct path. All values come from environment configuration so
 * production can point at self-hosted coturn without code changes.
 */
class WebRtcService
{
    public function iceServers(): array
    {
        $servers = [];

        $stunUrls = config('services.webrtc.stun_urls');
        if (!empty($stunUrls)) {
            $servers[] = ['urls' => array_map('trim', explode(',', $stunUrls))];
        }

        $turnUrl = config('services.webrtc.turn_url');
        if (!empty($turnUrl)) {
            $servers[] = [
                'urls' => array_map('trim', explode(',', $turnUrl)),
                'username' => (string) config('services.webrtc.turn_username'),
                'credential' => (string) config('services.webrtc.turn_credential'),
            ];
        }

        return $servers;
    }
}
