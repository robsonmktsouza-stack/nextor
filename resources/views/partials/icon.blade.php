@php($size = $size ?? 19)
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="{{ $class ?? '' }}">
@switch($name)
@case('menu')<path d="M4 6h16M4 12h16M4 18h16"/>@break
@case('search')<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>@break
@case('panel')<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M9 9h12"/>@break
@case('products')<path d="m3 7 9-4 9 4v10l-9 4-9-4V7ZM3 7l9 4 9-4M12 11v10"/>@break
@case('services')<path d="M9 6V4h6v2M4 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z"/><path d="M2 12h20M9 12v2h6v-2"/>@break
@case('tag')<path d="M20 13 13 20 4 11V4h7l9 9Z"/><circle cx="8.5" cy="8.5" r="1.2"/>@break
@case('stock')<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 10h18M8 14h8"/>@break
@case('sales')<circle cx="9" cy="20" r="1"/><circle cx="19" cy="20" r="1"/><path d="M2 3h2l2.4 12.1a2 2 0 0 0 2 1.6h9.9a2 2 0 0 0 2-1.6L22 7H5"/>@break
@case('customers')<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('chevron')<path d="m9 18 6-6-6-6"/>@break
@case('down')<path d="m6 9 6 6 6-6"/>@break
@case('logout')<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>@break
@case('x')<path d="M18 6 6 18M6 6l12 12"/>@break
@case('check')<path d="m20 6-11 11-5-5"/>@break
@case('alert')<path d="m10.3 4.3-8.6 15A2 2 0 0 0 3.5 22h17a2 2 0 0 0 1.7-2.7l-8.5-15a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>@break
@case('money')<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/>@break
@case('arrow-left')<path d="m12 19-7-7 7-7M5 12h14"/>@break
@case('trash')<path d="M3 6h18M8 6V4h8v2M5 6l1 15h12l1-15M10 10v7M14 10v7"/>@break
@case('copy')<rect x="8" y="8" width="11" height="11" rx="1"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>@break
@case('print')<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z"/><path d="M18 12h.01"/>@break
@case('download')<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>@break
@case('refresh')<path d="M20 6v5h-5M4 18v-5h5"/><path d="M6.1 9A7 7 0 0 1 18.2 6.2L20 11M4 13l1.8 4.8A7 7 0 0 0 17.9 15"/>@break
@case('filter')<path d="M4 5h16l-6 7v5l-4 2v-7L4 5Z"/>@break
@case('chevron-left')<path d="m15 18-6-6 6-6"/>@break
@case('edit')<path d="m16 4 4 4M3 17l-.5 4.5L7 21 20 8a2.8 2.8 0 0 0-4-4L3 17Z"/>@break
@case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
@case('settings')<path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/><circle cx="12" cy="12" r="5"/>@break
@case('shield')<path d="m12 22-7-4V5l7-3 7 3v13l-7 4ZM9 12l2 2 4-4"/>@break
@case('receipt')<path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3ZM8 8h8M8 12h8"/>@break
@case('pdv')<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M7 20h10M12 16v4M7 8h4M7 12h7"/>@break
@case('barcode')<path d="M3 5v14M6 5v14M9 5v14M13 5v14M16 5v14M20 5v14"/>@break
@case('expand')<path d="M8 3H3v5M16 3h5v5M21 16v5h-5M3 16v5h5"/>@break
@case('layers')<path d="m12 3 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 16l9 5 9-5"/>@break
@default<circle cx="12" cy="12" r="9"/>@endswitch
</svg>
