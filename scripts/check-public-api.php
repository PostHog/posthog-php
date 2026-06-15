<?php

declare(strict_types=1);

require_once __DIR__ . '/PublicApiSnapshot.php';

exit(PostHog\Scripts\PublicApiSnapshot::run($argv));
