<?php

/*
 * This file is part of the Liip/FunctionalTestBundle
 *
 * (c) Lukas Kahwe Smith <smith@pooteeweet.org>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Liip\FunctionalTestBundle\Database\Backup;

use Doctrine\Common\DataFixtures\Executor\AbstractExecutor;
use Doctrine\ORM\EntityManager;

/**
 * @author Aleksey Tupichenkov <alekseytupichenkov@gmail.com>
 */
final class MysqlDatabaseBackup extends AbstractDatabaseBackup implements DatabaseBackupInterface
{
    protected static $referenceData;

    protected static $sql;

    protected static $metadata;

    protected static $schemaUpdatedFlag = false;

    public function getBackupFilePath(): string
    {
        return $this->container->getParameter('kernel.cache_dir').'/test_mysql_'.md5(serialize($this->metadatas).serialize($this->classNames)).'.sql';
    }

    public function getReferenceBackupFilePath(): string
    {
        return $this->getBackupFilePath().'.ser';
    }

    protected function getBackup()
    {
        if (empty(self::$sql)) {
            self::$sql = file_get_contents($this->getBackupFilePath());
        }

        return self::$sql;
    }

    protected function getReferenceBackup(): string
    {
        if (empty(self::$referenceData)) {
            self::$referenceData = file_get_contents($this->getReferenceBackupFilePath());
        }

        return self::$referenceData;
    }

    public function isBackupExists(): bool
    {
        return
            file_exists($this->getBackupFilePath()) &&
            file_exists($this->getReferenceBackupFilePath()) &&
            $this->isBackupUpToDate($this->getBackupFilePath());
    }

    public function backup(AbstractExecutor $executor): void
    {
        /** @var EntityManager $em */
        $em = $executor->getReferenceRepository()->getManager();
        $connection = $em->getConnection();

        $params = $connection->getParams();
        if (isset($params['master'])) {
            $params = $params['master'];
        }

        $dbName = isset($params['dbname']) ? $params['dbname'] : '';
        $dbHost = isset($params['host']) ? $params['host'] : '';
        $dbPort = isset($params['port']) ? $params['port'] : '';
        $dbUser = isset($params['user']) ? $params['user'] : '';
        $dbPass = isset($params['password']) ? $params['password'] : '';

        $executor->getReferenceRepository()->save($this->getBackupFilePath());
        self::$metadata = $em->getMetadataFactory()->getLoadedMetadata();

        exec("mysqldump -h $dbHost -u $dbUser -p$dbPass --no-create-info --skip-triggers --no-create-db --no-tablespaces --compact $dbName > {$this->getBackupFilePath()}");
    }

    public function restore(AbstractExecutor $executor): void
    {
        /** @var EntityManager $em */
        $em = $executor->getReferenceRepository()->getManager();
        $connection = $em->getConnection();

        $connection->query('SET FOREIGN_KEY_CHECKS = 0;');
        $this->updateSchemaIfNeed($em);
        $truncateSql = [];
        foreach ($this->metadatas as $classMetadata) {
            $truncateSql[] = 'DELETE FROM '.$classMetadata->table['name']; // in small tables it's really faster than truncate
        }
        $connection->query(implode(';', $truncateSql));
        $connection->query($this->getBackup());
        $connection->query('SET FOREIGN_KEY_CHECKS = 1;');

        if (self::$metadata) {
            // it need for better performance
            foreach (self::$metadata as $class => $data) {
                $em->getMetadataFactory()->setMetadataFor($class, $data);
            }
            $executor->getReferenceRepository()->unserialize($this->getReferenceBackup());
        } else {
            $executor->getReferenceRepository()->unserialize($this->getReferenceBackup());
            self::$metadata = $em->getMetadataFactory()->getLoadedMetadata();
        }
    }
}
