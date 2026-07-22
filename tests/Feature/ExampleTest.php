<?php

// Die Startseite ist bewusst eine Weiterleitung auf die Wettkampfübersicht
// (routes/web.php), es gibt keine eigene Landingpage.

test('Startseite leitet auf die Wettkampfübersicht weiter', function () {
    $this->get(route('home'))->assertRedirect('/meets');
});
