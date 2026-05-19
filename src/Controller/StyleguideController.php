<?php
/**
 * Design tokens reference (Slice 6) — mirrors the shared Seismo / Magnitu style system.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;

final class StyleguideController
{
    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/styleguide.php';
    }
}
