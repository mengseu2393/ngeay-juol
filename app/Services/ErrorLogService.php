<?php

namespace App\Services;

use App\Enums\ErrorLogType;
use App\Models\ErrorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Records failures into `error_logs` so super admins see them in /admin without
 * server access. Wired from bootstrap/app.php's exception handler.
 *
 * Must never throw: it runs while an error is already being handled, and the
 * failure may be the database itself — then the entry is simply lost (the file
 * log in storage/logs still has it).
 */
class ErrorLogService
{
    /** Input keys never stored, matched case-insensitively on the key name. */
    protected const SECRET_KEYS = ['password', 'token', 'secret', 'pin', 'otp', 'card', 'cvv'];

    public static function recordException(Throwable $e): void
    {
        static::write([
            'type' => ErrorLogType::Exception,
            'message' => Str::limit($e->getMessage() ?: class_basename($e), 2000),
            'exception_class' => get_class($e),
            'file' => Str::limit(Str::after($e->getFile(), base_path().DIRECTORY_SEPARATOR), 250),
            'line' => $e->getLine(),
            'trace' => Str::limit($e->getTraceAsString(), 60000),
        ]);
    }

    public static function recordValidation(ValidationException $e, Request $request): void
    {
        static::write([
            'type' => ErrorLogType::Validation,
            'message' => Str::limit(collect($e->errors())->flatten()->implode(' | '), 2000),
            'exception_class' => get_class($e),
            'context' => [
                'errors' => $e->errors(),
                'input' => static::safeInput($request->except(['_token', '_method'])),
            ],
        ]);
    }

    /**
     * Validation thrown inside a Livewire component (every Filament create/edit
     * form and action). The request URL is always /livewire/update, so the page
     * comes from the Referer instead.
     */
    public static function recordLivewireValidation(ValidationException $e, object $component): void
    {
        $state = property_exists($component, 'data') && is_array($component->data ?? null)
            ? $component->data
            : [];

        static::write([
            'type' => ErrorLogType::Validation,
            'message' => Str::limit(collect($e->errors())->flatten()->implode(' | '), 2000),
            'exception_class' => get_class($e),
            'url' => Str::limit((string) (request()->header('Referer') ?: request()->fullUrl()), 2000),
            'route_name' => $component::class,
            'context' => [
                'errors' => $e->errors(),
                'input' => static::safeInput($state),
            ],
        ]);
    }

    /**
     * An error the code caught and turned into a danger notification instead of
     * letting it fail the request — the user saw a red toast, nobody else saw
     * anything. Call it right next to that Notification.
     */
    public static function recordHandled(Throwable $e, ?string $shownAs = null): void
    {
        $message = $e instanceof ValidationException
            ? collect($e->errors())->flatten()->implode(' | ')
            : ($e->getMessage() ?: class_basename($e));

        static::write([
            'type' => ErrorLogType::ActionFailed,
            'message' => Str::limit(($shownAs ? $shownAs.': ' : '').$message, 2000),
            'exception_class' => get_class($e),
            'file' => Str::limit(Str::after($e->getFile(), base_path().DIRECTORY_SEPARATOR), 250),
            'line' => $e->getLine(),
            'trace' => Str::limit($e->getTraceAsString(), 60000),
            'url' => Str::limit((string) (request()->header('Referer') ?: request()->fullUrl()), 2000),
        ]);
    }

    /** Only statuses that silently stop a real user; 404/403 are mostly bot noise. */
    public static function recordBlocked(HttpExceptionInterface $e): void
    {
        $status = $e->getStatusCode();

        static::write([
            'type' => ErrorLogType::Blocked,
            'message' => match ($status) {
                419 => 'HTTP 419 Page expired — CSRF token/session mismatch',
                429 => 'HTTP 429 Too many requests — rate limit hit',
                default => 'HTTP '.$status,
            },
            'exception_class' => get_class($e->getPrevious() ?? $e),
            'context' => ['status' => $status],
        ]);
    }

    /** @param  array<string, mixed>  $input */
    public static function safeInput(array $input): array
    {
        $clean = [];

        foreach ($input as $key => $value) {
            if (Str::contains(Str::lower((string) $key), self::SECRET_KEYS)) {
                $clean[$key] = filled($value) ? '[hidden]' : '';
            } elseif (is_array($value)) {
                $clean[$key] = static::safeInput($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = Str::limit((string) $value, 500);
            } else {
                $clean[$key] = '['.get_debug_type($value).']';
            }
        }

        return $clean;
    }

    /** @param  array<string, mixed>  $attributes */
    protected static function write(array $attributes): void
    {
        try {
            // Feature tests run "in console" but go through real HTTP requests.
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            ErrorLog::create($attributes + [
                'method' => $request?->method(),
                'url' => $request ? Str::limit($request->fullUrl(), 2000) : 'console: '.implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 6)),
                'route_name' => $request?->route()?->getName(),
                // Auth::id() would fall back to a stale session id for a deleted user.
                'user_id' => $request ? Auth::user()?->getKey() : null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 1000) : null,
            ]);
        } catch (Throwable) {
            // Swallowed on purpose — see class docblock.
        }
    }
}
