<?php

declare(strict_types=1);

namespace Dozor\Tracing;

enum RequestLifecycleStage: string
{
    case Bootstrap = 'bootstrap';
    case BeforeMiddleware = 'before_middleware';
    case Action = 'action';
    case AfterMiddleware = 'after_middleware';
    case Middleware = 'middleware';
    case Controller = 'controller';
    case Render = 'render';
    case Sending = 'sending';
    case Terminating = 'terminating';
    case End = 'end';
    case Cache = 'cache';
    case Query = 'query';
    case OutgoingHttp = 'outgoing_http';
    case Job = 'job';

    public static function fromName(string $name): ?self
    {
        return self::tryFrom($name);
    }

    public function isRequestPhase(): bool
    {
        return match ($this) {
            self::Bootstrap,
            self::BeforeMiddleware,
            self::Action,
            self::AfterMiddleware,
            self::Middleware,
            self::Controller,
            self::Render,
            self::Sending,
            self::Terminating => true,
            default => false,
        };
    }

    public function isLifecyclePhase(): bool
    {
        return match ($this) {
            self::Bootstrap,
            self::BeforeMiddleware,
            self::Action,
            self::AfterMiddleware,
            self::Middleware,
            self::Controller,
            self::Render,
            self::Sending,
            self::Terminating,
            self::End => true,
            default => false,
        };
    }
}
