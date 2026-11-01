<?php

declare(strict_types=1);

namespace KayedSpace\McpBridge\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use KayedSpace\McpBridge\Models\McpToken;
use RuntimeException;

/**
 * A user's own agent tokens, on a panel page.
 *
 * `mcp-bridge:token` issues a token for anyone, which is an operator's job.
 * This page issues one for the person looking at it and only for them,
 * because a token is the credential an agent acts with, and the person it
 * acts as is the one who should hand it out and take it back.
 */
class McpTokens extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'MCP tokens';

    protected static ?string $title = 'MCP tokens';

    protected static ?string $slug = 'mcp-tokens';

    protected string $view = 'mcp-bridge::filament.pages.mcp-tokens';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => McpToken::query()->whereMorphedTo('tokenable', $this->user())->latest())
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('scopes'),
                TextColumn::make('client')->placeholder('any'),
                TextColumn::make('last_used_at')->dateTime()->placeholder('never'),
                TextColumn::make('expires_at')->dateTime()->placeholder('never'),
                TextColumn::make('revoked_at')->dateTime()->placeholder('active'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('An agent connected with this token stops at its next request, and work it already queued stops too.')
                    ->visible(fn (McpToken $record): bool => $record->isUsable())
                    ->action(fn (McpToken $record) => $record->revoke()),
            ])
            ->emptyStateHeading('No tokens yet')
            ->emptyStateDescription('Issue one, and give it only the scopes the agent needs.');
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue a token')
                ->icon(Heroicon::OutlinedPlus)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->placeholder('Claude')
                        ->helperText('What this token is for, so it can be told apart later.'),
                    TagsInput::make('scopes')
                        ->default(['*'])
                        ->placeholder('courses:read')
                        ->helperText('* holds every scope. An empty list holds nothing. Give the agent only what it needs.'),
                    TextInput::make('client')
                        ->placeholder('claude-code')
                        ->helperText('Which agent this is for, recorded in the audit trail.'),
                    DateTimePicker::make('expires_at')
                        ->default(Carbon::now()->addDays(90))
                        ->helperText('Leave empty for a token that never expires, which is a decision rather than a default.'),
                ])
                ->action(function (array $data): void {
                    $plain = McpToken::issue(
                        tokenable: $this->user(),
                        name: is_string($data['name'] ?? null) ? $data['name'] : 'Agent token',
                        scopes: $this->scopes($data['scopes'] ?? null),
                        expiresAt: $this->expiry($data['expires_at'] ?? null),
                        client: is_string($data['client'] ?? null) && $data['client'] !== '' ? $data['client'] : null,
                    )->plainText;

                    // The only moment the plain token exists. Persistent, so it
                    // is not gone before it was copied.
                    Notification::make()
                        ->title('Token issued. It is shown once.')
                        ->body($plain)
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /** @return list<string> */
    protected function scopes(mixed $scopes): array
    {
        if (! is_array($scopes)) {
            return ['*'];
        }

        return array_values(array_filter(array_map(
            fn (mixed $scope): string => is_string($scope) ? trim($scope) : '',
            $scopes,
        ), fn (string $scope): bool => $scope !== ''));
    }

    protected function expiry(mixed $expires): ?Carbon
    {
        if ($expires instanceof Carbon) {
            return $expires;
        }

        return is_string($expires) && $expires !== '' ? Carbon::parse($expires) : null;
    }

    /**
     * Whoever is signed in to the panel. A token belongs to a model, so a
     * panel whose user is not one has nothing here to attach a token to.
     */
    protected function user(): Model
    {
        $user = Filament::auth()->user();

        if (! $user instanceof Model) {
            throw new RuntimeException('The MCP tokens page needs a signed-in Eloquent user to issue tokens for.');
        }

        return $user;
    }
}
