<?php

return ['otp_ttl' => (int) env('OTP_TTL_MINUTES', 10), 'otp_max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5), 'private_access_minutes' => (int) env('PRIVATE_ACCESS_MINUTES', 30)];
