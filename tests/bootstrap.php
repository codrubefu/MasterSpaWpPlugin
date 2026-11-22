<?php
/**
 * Unit tests run without WordPress: WP/WC functions are stubbed with Brain Monkey.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MASTERSPA_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'MASTERSPA_PLUGIN_URL', 'http://example.test/wp-content/plugins/MasterSpaWpPlugin/' );
define( 'MASTERSPA_VERSION', 'test' );
define( 'MASTERSPA_AS_GROUP', 'masterspa' );

require_once __DIR__ . '/TestCase.php';
