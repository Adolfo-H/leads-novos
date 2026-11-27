<?php

// EC_ATTACHMENT_V29: do not run the superseded heuristic installer.
fwrite(STDERR, "Conciliação V28 substituída. Execute: php tools/proposals/reconcile_v29.php apply CONCILIAR_ANEXOS_V29\n");
exit(1);
