<?php

namespace Sentry\Laravel;

use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\Scope;

class SentryHandler extends AbstractProcessingHandler
{
    /**
     * @var string the current application environment (staging|preprod|prod)
     */
    protected $environment;

    /**
     * @var string should represent the current version of the calling
     *             software. Can be any string (git commit, version number)
     */
    protected $release;

    /**
     * @var Hub the hub object that sends the message to the server
     */
    protected $hub;

    /**
     * @var FormatterInterface The formatter to use for the logs generated via handleBatch()
     */
    protected $batchFormatter;

    /**
     * @param Hub  $hub
     * @param int  $level  The minimum logging level at which this handler will be triggered
     * @param bool $bubble Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct(Hub $hub, $level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->hub = $hub;
    }

    /**
     * {@inheritdoc}
     */
    public function handleBatch(array $records): void
    {
        $level = $this->level;

        // filter records based on their level
        $records = array_filter(
            $records,
            function ($record) use ($level) {
                return $record['level'] >= $level;
            }
        );

        if (!$records) {
            return;
        }

        // the record with the highest severity is the "main" one
        $record = array_reduce(
            $records,
            function ($highest, $record) {
                if ($record['level'] > $highest['level']) {
                    return $record;
                }

                return $highest;
            }
        );

        // the other ones are added as a context item
        $logs = [];
        foreach ($records as $r) {
            $logs[] = $this->processRecord($r);
        }

        if ($logs) {
            $record['context']['logs'] = (string)$this->getBatchFormatter()->formatBatch($logs);
        }

        $this->handle($record);
    }

    /**
     * Sets the formatter for the logs generated by handleBatch().
     *
     * @param FormatterInterface $formatter
     *
     * @return \Sentry\Laravel\SentryHandler
     */
    public function setBatchFormatter(FormatterInterface $formatter): self
    {
        $this->batchFormatter = $formatter;

        return $this;
    }

    /**
     * Gets the formatter for the logs generated by handleBatch().
     */
    public function getBatchFormatter(): FormatterInterface
    {
        if (!$this->batchFormatter) {
            $this->batchFormatter = $this->getDefaultBatchFormatter();
        }

        return $this->batchFormatter;
    }

    /**
     * Translates Monolog log levels to Sentry Severity.
     *
     * @param int $logLevel
     *
     * @return \Sentry\Severity
     */
    protected function getLogLevel($logLevel)
    {
        switch ($logLevel) {
            case Logger::DEBUG:
                return Severity::debug();
            case Logger::NOTICE:
            case Logger::INFO:
                return Severity::info();
            case Logger::WARNING:
                return Severity::warning();
            case Logger::ERROR:
                return Severity::error();
            case Logger::ALERT:
            case Logger::EMERGENCY:
            case Logger::CRITICAL:
                return Severity::fatal();
        }
    }

    /**
     * {@inheritdoc}
     * @suppress PhanTypeMismatchArgument
     */
    protected function write(array $record): void
    {
        $this->hub->withScope(
            function (Scope $scope) use ($record) {
                if (!empty($record['context']['extra'])) {
                    foreach ($record['context']['extra'] as $key => $tag) {
                        $scope->setExtra($key, $tag);
                    }
                    unset($record['context']['extra']);
                }

                if (!empty($record['context']['tags'])) {
                    foreach ($record['context']['tags'] as $key => $tag) {
                        $scope->setTag($key, $tag);
                    }
                    unset($record['context']['tags']);
                }

                if (!empty($record['extra'])) {
                    foreach ($record['extra'] as $key => $extra) {
                        $scope->setExtra($key, $extra);
                    }
                }

                if (!empty($record['context']['fingerprint'])) {
                    $scope->setFingerprint($record['context']['fingerprint']);
                    unset($record['context']['fingerprint']);
                }

                if (!empty($record['context']['user'])) {
                    $scope->setUser((array)$record['context']['user'], true);
                    unset($record['context']['user']);
                }

                $scope->addEventProcessor(
                    function (Event $event) use ($record) {
                        if (!empty($record['context']['logger'])) {
                            $event->setLogger($record['context']['logger']);
                            unset($record['context']['logger']);
                        } else {
                            $event->setLogger($record['channel']);
                        }

                        if (!empty($this->environment) && !$event->getEnvironment()) {
                            $event->setEnvironment($this->environment);
                        }

                        if (!empty($this->release) && !$event->getRelease()) {
                            $event->setRelease($this->release);
                        }

                        return $event;
                    }
                );

                if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
                    $this->hub->captureException($record['context']['exception']);
                } else {
                    $this->hub->captureMessage($record['formatted'], $this->getLogLevel($record['level']));
                }
            }
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LineFormatter('[%channel%] %message%');
    }

    /**
     * Gets the default formatter for the logs generated by handleBatch().
     *
     * @return FormatterInterface
     */
    protected function getDefaultBatchFormatter(): FormatterInterface
    {
        return new LineFormatter();
    }

    /**
     * Set the release.
     *
     * @param string $value
     *
     * @return self
     */
    public function setRelease($value): self
    {
        $this->release = $value;

        return $this;
    }

    /**
     * Set the current application environment.
     *
     * @param string $value
     *
     * @return self
     */
    public function setEnvironment($value): self
    {
        $this->environment = $value;

        return $this;
    }

    /**
     * Add a breadcrumb.
     *
     * @link https://docs.sentry.io/learn/breadcrumbs/
     *
     * @param \Sentry\Breadcrumb $crumb
     *
     * @return \Sentry\Laravel\SentryHandler
     */
    public function addBreadcrumb(Breadcrumb $crumb): self
    {
        $this->hub->addBreadcrumb($crumb);

        return $this;
    }
}
