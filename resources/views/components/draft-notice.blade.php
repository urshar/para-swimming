{{--
    x-draft-notice — auffälliger Hinweis auf einer noch nicht rechtsgültigen Seite (Impressum,
    Datenschutzerklärung, Phase 9 Nachtrag). role="note" statt reinem <div>, damit
    Screenreader-Nutzer:innen den Hinweis nicht überlesen wie normalen Fließtext — kein
    role="alert"/aria-live: der Hinweis steht dauerhaft auf der Seite, ist keine dynamische
    Statusmeldung (docs/accessibility.md: "Statusmeldungen … über aria-live", das gilt hier
    nicht).
--}}
<div role="note"
     class="mb-8 max-w-2xl rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-200">
    <p class="font-semibold">{{ __('public.draft_notice.heading') }}</p>
    <p class="mt-1">{{ __('public.draft_notice.text') }}</p>
</div>
