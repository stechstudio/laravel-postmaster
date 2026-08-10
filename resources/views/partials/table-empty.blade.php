{{-- The "nothing here" row for a listing table. colspan is clamped by the
     browser to the table's real width, so this doesn't have to be kept in
     step with a header whose columns come and go with tenancy. --}}
<tr class="pm-row-empty">
    <td class="pm-cell-full" colspan="99"><div class="pm-empty">{{ $note }}</div></td>
</tr>
