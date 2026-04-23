<?php

declare(strict_types=1);

namespace MatthiasVanGorp\ErrorReporter\Log;

use MatthiasVanGorp\ErrorReporter\ErrorReporter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

final class ErrorReporterLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly ErrorReporter $reporter,
        int|string|Level $level = Level::Error,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $this->reporter->captureLog(
                level: strtolower($record->level->getName()),
                message: $record->message,
                context: $record->context,
                channel: $record->channel,
            );
        } catch (Throwable) {
            // Swallow — a failing reporter must never block the host log pipeline.
        }
    }
}
