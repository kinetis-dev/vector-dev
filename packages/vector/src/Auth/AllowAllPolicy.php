<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

/** The default policy: whoever the access policy admitted may do everything. */
final class AllowAllPolicy extends ResourcePolicy {}
