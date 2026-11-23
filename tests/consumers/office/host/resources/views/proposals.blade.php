<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Vaulter AI — Предложения и задания</title>
</head>
<body>
<main class="vai-workspace-stand" data-viewer="{{ $viewer->effectiveSubject }}">
    <header>
        <h1>Предложения на согласование</h1>
        <p data-testid="stand-viewer">Текущий пользователь: <strong>{{ $viewer->effectiveSubject }}</strong></p>
    </header>

    @if ($errorCode !== null)
        <div role="alert" data-testid="stand-error">{{ $errorCode }}</div>
    @endif

    @if ($run !== null)
        <section data-testid="stand-run" data-run-id="{{ $run['run_id'] }}" data-run-state="{{ $run['state'] }}">
            <h2>Задание <code>{{ $run['run_id'] }}</code></h2>
            <p>Статус задания: <strong>{{ $run['state'] }}</strong> · Инициатор: {{ $run['initiator'] }}</p>

            @if (($run['confirmed_steps'] ?? []) !== [])
                <div data-testid="stand-confirmed-steps">
                    <h3>Подтверждённые шаги</h3>
                    <ul>
                        @foreach ($run['confirmed_steps'] as $step)
                            <li data-resource-id="{{ $step['resource_id'] }}">
                                {{ $step['title'] ?? $step['resource_id'] }} — {{ $step['status'] }}
                                @if (! empty($step['execution']['receipt_id']))
                                    · квитанция <code>{{ $step['execution']['receipt_id'] }}</code>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (($run['remaining_steps'] ?? []) !== [])
                <div data-testid="stand-remaining-steps">
                    <h3>Оставшиеся шаги</h3>
                    <ul>
                        @foreach ($run['remaining_steps'] as $step)
                            <li data-resource-id="{{ $step['resource_id'] }}">
                                {{ $step['title'] ?? $step['resource_id'] }} — {{ $step['status'] }}
                                @if (! empty($step['error']))
                                    (<code>{{ $step['error'] }}</code>)
                                @endif
                                @if (! empty($step['requires_reread']))
                                    <span data-testid="stand-requires-reread">Требуется повторное чтение цели</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($run['can_resume']))
                <form method="POST" action="{{ url('/office/runs/'.$run['run_id'].'/resume') }}">
                    @csrf
                    <input type="hidden" name="redirect_to" value="{{ $currentUrl }}">
                    <button type="submit" data-action="resume-run">Продолжить незавершённые шаги</button>
                </form>
            @endif
        </section>
    @endif

    <section data-testid="stand-proposals-list">
        @forelse ($proposals as $proposal)
            @php
                $approval = $proposal['approval'] ?? [];
                $execution = $proposal['execution'] ?? [];
                $verification = $proposal['verification'] ?? [];
                $diff = $proposal['diff'] ?? null;
                $humanStatus = (string) ($approval['human_status'] ?? 'pending');
                $ownerStatus = (string) ($execution['owner_status'] ?? 'not_started');
                $verifyStatus = (string) ($verification['status'] ?? 'unverified');
            @endphp
            <article
                data-proposal-id="{{ $proposal['proposal_id'] }}"
                data-digest="{{ $proposal['digest'] }}"
                data-human-status="{{ $humanStatus }}"
                data-owner-status="{{ $ownerStatus }}"
                data-verification-status="{{ $verifyStatus }}"
            >
                <h3>{{ $proposal['resource']['title'] ?? $proposal['resource']['resource_id'] }}</h3>
                <p>
                    Ресурс: <code>{{ $proposal['resource']['resource_id'] }}</code>
                    · Инициатор: <span>{{ $proposal['initiator'] }}</span>
                    @if (! empty($approval['reviewer']))
                        · Рецензент: <span>{{ $approval['reviewer'] }}</span>
                    @endif
                </p>
                <p>
                    ID предложения: <code>{{ $proposal['proposal_id'] }}</code>
                    · Digest: <code>{{ $proposal['digest'] }}</code>
                    @if (! empty($execution['command_id']))
                        · command_id: <code>{{ $execution['command_id'] }}</code>
                    @endif
                    @if (! empty($execution['receipt_id']))
                        · Квитанция: <code>{{ $execution['receipt_id'] }}</code>
                    @endif
                </p>
                <div>
                    <span data-status="human">
                        @if ($humanStatus === 'approved')
                            Принято человеком
                        @elseif ($humanStatus === 'rejected')
                            Отклонено человеком
                        @elseif ($humanStatus === 'automatic')
                            Автоматически
                        @else
                            Ожидает решения человека
                        @endif
                    </span>
                    |
                    <span data-status="owner">
                        @if ($ownerStatus === 'committed')
                            Сохранено владельцем
                        @elseif ($ownerStatus === 'pending_dispatch')
                            Ожидает отправки владельцу
                        @elseif ($ownerStatus === 'running')
                            Сохраняется владельцем
                        @elseif ($ownerStatus === 'rejected')
                            Отклонено владельцем
                        @else
                            Не отправлено владельцу
                        @endif
                    </span>
                    |
                    <span data-status="verification">
                        @if ($verifyStatus === 'passed')
                            Проверено
                        @elseif ($verifyStatus === 'failed')
                            Ошибка проверки
                        @else
                            Не проверено
                        @endif
                    </span>
                </div>

                @if (is_array($diff) && ($diff['kind'] ?? '') === 'text_replace')
                    <div data-testid="stand-diff">
                        @if (! empty($diff['section_path']))
                            <p>Раздел: {{ implode(' / ', $diff['section_path']) }}</p>
                        @endif
                        <p>
                            Было:
                            @foreach (($diff['before_segments'] ?? []) as $seg)
                                @php $markTypes = array_column($seg['marks'] ?? [], 'type'); @endphp
                                @if (in_array('bold', $markTypes, true) || in_array('strong', $markTypes, true))
                                    <strong>{{ $seg['text'] ?? '' }}</strong>
                                @elseif (in_array('italic', $markTypes, true) || in_array('em', $markTypes, true))
                                    <em>{{ $seg['text'] ?? '' }}</em>
                                @else
                                    <span>{{ $seg['text'] ?? '' }}</span>
                                @endif
                            @endforeach
                        </p>
                        <p>
                            Станет:
                            @foreach (($diff['after_segments'] ?? []) as $seg)
                                @php $markTypes = array_column($seg['marks'] ?? [], 'type'); @endphp
                                @if (in_array('bold', $markTypes, true) || in_array('strong', $markTypes, true))
                                    <strong>{{ $seg['text'] ?? '' }}</strong>
                                @elseif (in_array('italic', $markTypes, true) || in_array('em', $markTypes, true))
                                    <em>{{ $seg['text'] ?? '' }}</em>
                                @else
                                    <span>{{ $seg['text'] ?? '' }}</span>
                                @endif
                            @endforeach
                        </p>
                    </div>
                @elseif (is_array($diff) && ($diff['kind'] ?? '') === 'sheet_fill_formula')
                    <div data-testid="stand-diff">
                        <p>
                            Лист <code>{{ $diff['sheet_id'] ?? '' }}</code>
                            · таблица <code>{{ $diff['table_id'] ?? '' }}</code>
                            · колонка <code>{{ $diff['destination_column_letter'] ?? '' }}</code>
                            · формула <code>{{ $diff['formula_template'] ?? '' }}</code>
                        </p>
                    </div>
                @endif

                @if (! empty($approval['can_approve']))
                    <form method="POST" action="{{ url('/office/proposals/'.$proposal['proposal_id'].'/decision') }}" style="display:inline">
                        @csrf
                        <input type="hidden" name="digest" value="{{ $proposal['digest'] }}">
                        <input type="hidden" name="approved" value="1">
                        <input type="hidden" name="redirect_to" value="{{ $currentUrl }}">
                        <button type="submit" data-action="approve">Принять предложение</button>
                    </form>
                @endif

                @if (! empty($approval['can_reject']))
                    <form method="POST" action="{{ url('/office/proposals/'.$proposal['proposal_id'].'/decision') }}" style="display:inline">
                        @csrf
                        <input type="hidden" name="digest" value="{{ $proposal['digest'] }}">
                        <input type="hidden" name="approved" value="0">
                        <input type="hidden" name="redirect_to" value="{{ $currentUrl }}">
                        <button type="submit" data-action="reject">Отклонить</button>
                    </form>
                @endif
            </article>
        @empty
            <p data-testid="stand-empty">Нет доступных предложений.</p>
        @endforelse
    </section>
</main>
</body>
</html>
