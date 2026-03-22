@props([
    'code'  => '',
    'label' => null,
    'class' => 'w-6 h-4',
])

@php
    // IOC-Dreilettercode → ISO-2 für flag-icons CDN
    $iocToIso = [
        'AFG' => 'af', 'ALB' => 'al', 'ALG' => 'dz', 'AND' => 'ad',
        'ANG' => 'ao', 'ARG' => 'ar', 'ARM' => 'am', 'AUS' => 'au',
        'AUT' => 'at', 'AZE' => 'az', 'BEL' => 'be', 'BIH' => 'ba',
        'BLR' => 'by', 'BOL' => 'bo', 'BRA' => 'br', 'BUL' => 'bg',
        'CAN' => 'ca', 'CHI' => 'cl', 'CHN' => 'cn', 'COL' => 'co',
        'CRC' => 'cr', 'CRO' => 'hr', 'CUB' => 'cu', 'CYP' => 'cy',
        'CZE' => 'cz', 'DEN' => 'dk', 'DOM' => 'do', 'ECU' => 'ec',
        'EGY' => 'eg', 'ESP' => 'es', 'EST' => 'ee', 'ETH' => 'et',
        'FIN' => 'fi', 'FRA' => 'fr', 'GBR' => 'gb', 'GEO' => 'ge',
        'GER' => 'de', 'GHA' => 'gh', 'GRE' => 'gr', 'GUA' => 'gt',
        'HKG' => 'hk', 'HUN' => 'hu', 'INA' => 'id', 'IND' => 'in',
        'IRI' => 'ir', 'IRL' => 'ie', 'ISL' => 'is', 'ISR' => 'il',
        'ITA' => 'it', 'JPN' => 'jp', 'KAZ' => 'kz', 'KEN' => 'ke',
        'KOR' => 'kr', 'KOS' => 'xk', 'LAT' => 'lv', 'LIE' => 'li',
        'LTU' => 'lt', 'LUX' => 'lu', 'MAR' => 'ma', 'MAS' => 'my',
        'MDA' => 'md', 'MEX' => 'mx', 'MKD' => 'mk', 'MLT' => 'mt',
        'MNE' => 'me', 'MON' => 'mc', 'NED' => 'nl', 'NGR' => 'ng',
        'NOR' => 'no', 'NZL' => 'nz', 'PAN' => 'pa', 'PAR' => 'py',
        'PER' => 'pe', 'PHI' => 'ph', 'POL' => 'pl', 'POR' => 'pt',
        'PUR' => 'pr', 'ROU' => 'ro', 'RSA' => 'za', 'RUS' => 'ru',
        'SGP' => 'sg', 'SLO' => 'si', 'SMR' => 'sm', 'SRB' => 'rs',
        'SUI' => 'ch', 'SVK' => 'sk', 'SWE' => 'se', 'THA' => 'th',
        'TPE' => 'tw', 'TUN' => 'tn', 'TUR' => 'tr', 'UAE' => 'ae',
        'UKR' => 'ua', 'URU' => 'uy', 'USA' => 'us', 'UZB' => 'uz',
        'VEN' => 've',
    ];

    $iso   = $iocToIso[strtoupper($code)] ?? null;
    $title = $label ?? strtoupper($code);
@endphp

@if($iso)
    {{--
        flag-icons CDN: https://flagicons.lipis.dev
        Wird im Layout via CSS eingebunden:
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/lipis/flag-icons@7.2.3/css/flag-icons.min.css">
    --}}
    <span
        class="fi fi-{{ $iso }} {{ $class }} inline-block rounded-sm shadow-sm"
        title="{{ $title }}"
        aria-label="{{ $title }}"
    ></span>
@else
    <span
        title="{{ $title }}"
        class="inline-flex items-center justify-center rounded px-1 py-0.5 text-xs font-mono font-bold bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300"
    >{{ strtoupper($code) ?: '–' }}</span>
@endif
