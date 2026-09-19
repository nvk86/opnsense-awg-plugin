<?php

namespace OPNsense\AmneziaWG\Migrations;

use OPNsense\Base\BaseModelMigration;

/**
 * Migration 2.0.x -> 2.1.0.
 *
 * Health monitoring and Gateway Health Sync are deliberately opt-in so an
 * upgrade never changes an existing tunnel's monitoring or routing behavior.
 */
class M2_1_0 extends BaseModelMigration
{
    public function run($model)
    {
        foreach ($model->instance->iterateItems() as $node) {
            $nodes = [];
            if (trim((string)$node->health_monitor) === '') {
                $nodes['health_monitor'] = '0';
            }
            if (trim((string)$node->health_target) === '') {
                $nodes['health_target'] = '';
            }
            if (trim((string)$node->gateway_health_sync) === '') {
                $nodes['gateway_health_sync'] = '0';
            }
            if (!empty($nodes)) {
                $node->setNodes($nodes);
            }
        }
        parent::run($model);
    }
}
