<?php

declare(strict_types=1);

namespace Nvl\Payments\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Payments\Services\PaymentsDoctor;

/**
 * Renders the package-owned read-only installation diagnostics.
 */
final class PaymentsDoctorCommand extends Command
{
    protected $signature = 'nvl:payments:doctor {--strict} {--format=text}';

    protected $description = 'Check local Payments configuration, schema, and webhook readiness';

    /** Report local readiness without contacting Stripe or printing configuration values. */
    public function handle(PaymentsDoctor $doctor): int
    {

        $format = $this->option('format');
        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('Format must be text or json.');

            return self::FAILURE;
        }
        $checks = $doctor->inspect();

        if ($format === 'json') {
            $this->line(json_encode(['checks' => $checks], JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $name => $ready) {
                $this->line($name.': '.($ready ? 'ready' : 'missing or invalid'));
            }
            $this->line('Stripe endpoint registration and delivery must be verified in Stripe.');
        }

        return $this->option('strict') && in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
