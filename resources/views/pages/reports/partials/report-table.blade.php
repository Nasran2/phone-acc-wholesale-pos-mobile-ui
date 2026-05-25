<table class="{{ $print ? 'report-print-table' : 'w-full border-collapse text-left text-xs' }}">
    <thead>
        <tr class="{{ $print ? '' : 'border-b border-zinc-200 text-zinc-400 dark:border-zinc-800' }}">
            @foreach ($this->columns as $column)
                <th class="{{ $print ? '' : 'px-4 py-3 font-bold uppercase tracking-wider '.($column['align'] === 'right' ? 'text-right' : 'text-left') }}">
                    {{ __($column['label']) }}
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody class="{{ $print ? '' : 'divide-y divide-zinc-100 font-medium text-zinc-700 dark:divide-zinc-800 dark:text-zinc-300' }}">
        @forelse ($this->rows as $row)
            <tr wire:key="report-row-{{ $print ? 'print' : 'screen' }}-{{ $loop->index }}" class="{{ $print ? '' : 'hover:bg-zinc-50/70 dark:hover:bg-zinc-800/40' }}">
                @foreach ($this->columns as $column)
                    <td class="{{ $print ? ($column['align'] === 'right' ? 'text-right' : '') : 'px-4 py-3.5 '.($column['align'] === 'right' ? 'text-right' : 'text-left').' '.(($column['tone'] ?? null) ? $this->toneClass($column['tone']) : '') }}">
                        {{ $this->displayValue($row, $column) }}
                    </td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td colspan="{{ count($this->columns) }}" class="{{ $print ? 'text-center' : 'px-4 py-10 text-center text-sm font-medium text-zinc-400' }}">
                    {{ __($this->meta['empty']) }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
