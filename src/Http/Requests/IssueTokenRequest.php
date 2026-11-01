<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the panel's issue form sends. Authorisation is the panel middleware's,
 * which ran before this request was built.
 */
final class IssueTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'max:100'],
            'other_scopes' => ['nullable', 'string', 'max:500'],
            'client' => ['nullable', 'string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * The ticked scopes and the typed ones as one list. `*` alone is every
     * scope; nothing ticked and nothing typed is a token that holds nothing,
     * which is a choice the form states rather than a default it silently
     * widens.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        $ticked = $this->validated('scopes') ?? [];
        $typed = $this->validated('other_scopes');

        $scopes = [
            ...(is_array($ticked) ? $ticked : []),
            ...(is_string($typed) ? explode(',', $typed) : []),
        ];

        $scopes = array_values(array_unique(array_filter(array_map(
            fn (mixed $scope): string => is_string($scope) ? trim($scope) : '',
            $scopes,
        ), fn (string $scope): bool => $scope !== '')));

        return in_array('*', $scopes, true) ? ['*'] : $scopes;
    }
}
