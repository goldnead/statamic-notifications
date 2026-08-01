<?php

namespace Goldnead\Notifications\Http\Controllers\Cp;

use Goldnead\Notifications\Models\NotificationItem;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Facades\Scope;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Statamic;

/**
 * Read-only inspector: what was written, to whom, read or not, digested or not.
 * No sending, no editing — the CP is for answering "did this person get it?",
 * which is the question support actually asks.
 *
 * The screen is core's <ui-listing>, so search, sorting, filters, saved views,
 * column customisation and pagination belong to core rather than to us.
 * index() renders the shell; listing() answers the listing's own XHR in the
 * shape documented in ui-vocabulary §3.4.
 */
class NotificationController extends Controller
{
    use QueriesFilters;

    /** What a user may sort by, mapped to the column it actually sorts on. */
    private const SORTABLE = [
        'created_at' => 'created_at',
        'type' => 'type',
        'recipient' => 'email',
        'read_at' => 'read_at',
        'digested_at' => 'digested_at',
    ];

    private const PREFERENCES_PREFIX = 'notifications.listing';

    public function index()
    {
        Gate::authorize('view notifications');

        return view('notifications::cp.index', [
            'columns' => $this->columns()->rejectUnlisted()->values(),
            'filters' => Scope::filters('notifications'),
            'preferencesPrefix' => self::PREFERENCES_PREFIX,
        ]);
    }

    public function listing(FilteredRequest $request)
    {
        Gate::authorize('view notifications');

        $query = NotificationItem::query();

        $activeFilterBadges = $this->queryFilters($query, $request->filters ?? []);

        $this->search($query, (string) $request->query('search', ''));

        [$sort, $order] = $this->sort($request->query('sort'), $request->query('order'));

        $paginator = $query
            ->orderBy($sort, $order)
            ->orderByDesc('id')
            ->paginate(Statamic::cpPerPage($request->query('perPage')));

        return [
            'data' => collect($paginator->items())
                ->map(fn (NotificationItem $item) => $this->row($item))
                ->all(),
            'meta' => [
                'columns' => $this->visibleColumns()->values(),
                'activeFilterBadges' => $activeFilterBadges,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function show(int $id)
    {
        Gate::authorize('view notifications');

        // Brand scope still applies: an operator in brand A must not read a
        // brand B row by guessing its id.
        $notification = NotificationItem::query()->findOrFail($id);

        return view('notifications::cp.show', ['notification' => $notification]);
    }

    private function columns(): Columns
    {
        return new Columns([
            Column::make('created_at')->label(__('notifications::cp.col_created')),
            Column::make('type')->label(__('notifications::cp.col_type')),
            Column::make('recipient')->label(__('notifications::cp.col_recipient'))->sortable(false),
            Column::make('read_at')->label(__('notifications::cp.col_read')),
            Column::make('digested_at')->label(__('notifications::cp.col_digested')),
        ]);
    }

    /**
     * The same shape HasRequestedColumns::visibleColumns() produces, without a
     * resource class: the listing overwrites its local columns from
     * meta.columns on every response, so a column the user hid has to come
     * back marked hidden rather than not come back at all.
     */
    private function visibleColumns(): Columns
    {
        $columns = $this->columns()->setPreferred(self::PREFERENCES_PREFIX.'.columns');

        $requested = array_values(array_filter(explode(',', (string) request('columns'))));

        if ($requested === []) {
            return $columns;
        }

        $all = $columns->keyBy->field()->map->visible(false);

        return new Columns(
            collect($requested)
                ->filter(fn ($field) => $all->has($field))
                ->map(fn ($field) => $all->get($field)->visible(true))
                ->merge($all->except($requested))
                ->values()
                ->all()
        );
    }

    /** @return array{0: string, 1: string} */
    private function sort(?string $sort, ?string $order): array
    {
        return [
            self::SORTABLE[$sort] ?? 'created_at',
            strtolower((string) $order) === 'asc' ? 'asc' : 'desc',
        ];
    }

    private function search(mixed $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        $query->where(function ($query) use ($like): void {
            $query->where('type', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('user_id', 'like', $like)
                ->orWhere('message', 'like', $like)
                ->orWhere('dedupe_key', 'like', $like);
        });
    }

    /** @return array<string, mixed> */
    private function row(NotificationItem $item): array
    {
        return [
            'id' => $item->id,
            'created_at' => $item->created_at?->format('Y-m-d H:i'),
            'type' => $item->type,
            'recipient' => $item->email ?? $item->user_id ?? $item->contact_uuid,
            'read_at' => $item->read_at?->format('Y-m-d H:i'),
            'digested_at' => $item->digested_at?->format('Y-m-d H:i'),
            'is_read' => $item->read_at !== null,
            'url' => cp_route('notifications.show', ['id' => $item->id]),
        ];
    }
}
