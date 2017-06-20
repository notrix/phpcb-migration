<?php

/**
 * If Couchbase class exists (couchbase 1.x version used) then exit
 */
if (class_exists('Couchbase', false)) {
    return;
}

/**
 * Compatibility class to provide a link between the v1 and v2 PHP couchbase library
 *
 * In the v1 lib a "Couchbase" class was provided by the PHP module but it disapeared in v2.
 * This class provides the same methods using a CouchbaseCluster objet (the v2 object) underneath.
 *
 * Tested with client library : 2.0.3
 *
 * Note regarding expiry time :
 *    in the libcouchbase documentation (include/libcouchbase/couchbase.h line 1428) it is specified
 *    that the value of expiry time can be interpreted differently :
 *        if > 30days : the timestamp unix when the key must expire
 *        if < 30days : the number of seconds before the key will expire
 *    As we migth want to pass longer delays than 30 days the decision here is to compare the value given to the current timestamp
 *        if greater than "now" it is seen as a timestamp (and couchbase will too)
 *        if smaller than "now" it is seen as a delay and will be passed as now+delay so that there will be no ambiguity for couchbase
 *
 *
 * @author kevin
 */
class Couchbase
{
    /** @var \Couchbase\Cluster */
    protected $cluster;

    /** @var array */
    protected $clusterIps;

    /** @var \Couchbase\Bucket */
    protected $bucket;

    /** @var string */
    protected $bucketName;

    /** @var int */
    protected $lastResult = 0;

    /**
     * @var string
     */
    protected $encoderFormat;

    /**
     * Class constructor
     *
     * @param array  $ipsList
     * @param string $login
     * @param string $passwd
     * @param string $bucket
     * @param bool   $persist
     */
    public function __construct($ipsList, $login, $passwd, $bucket, $persist = false)
    {
        $this->bucketName = $bucket;
        $this->clusterIps = $ipsList;
        $this->encoderFormat = ini_get('couchbase.encoder.format') ?: 'json';

        $this->connectCluster($login, $passwd);
    }

    public function rawBucket()
    {
        return $this->bucket;
    }

    private function connectCluster($login, $passwd)
    {
        if (count($this->clusterIps) == 0) {
            throw new \Exception('no ips to connect to.');
        }

        $dsn = 'couchbase://'.implode(',', $this->clusterIps);
        try {
            $this->cluster = new \Couchbase\Cluster($dsn);

            if ($login) {
                $authenticator = new \Couchbase\ClassicAuthenticator();
                $authenticator->cluster($login, $passwd);

                $this->cluster->authenticate($authenticator);
            }

            // the connection is only made when we open the bucket
            $this->connectBucket();
            $this->lastResult = COUCHBASE_SUCCESS;
        } catch (\Exception $e) {
            error_log(__METHOD__.' : '.$dsn.' does not answer "'.$e->getMessage().'" ('.$e->getCode().'), trying another one ...');
            $this->lastResult = $e->getCode();

            if (!is_object($this->cluster)) {
                throw $e;
            }
        }
    }

    private function connectBucket()
    {
        $this->bucket = $this->cluster->openBucket($this->bucketName);
    }

    public function getResultCode()
    {
        return $this->lastResult;
    }

    /**
     * @return int the current timeout in usec (1/1000000th of a second)
     */
    public function getTimeout()
    {
        return $this->bucket->operationTimeout;
    }

    /**
     * @param int $usec
     *
     * @return bool
     */
    public function setTimeout($usec)
    {
        $this->bucket->operationTimeout = $usec;

        return true;
    }

    /**
     * @param type $id
     * @param type $cas
     *
     * @return bool true if successful
     *
     * @throws \Couchbase\Exception
     */
    public function unlock($id, $cas)
    {
        $options = array();

        if (!empty($cas)) {
            $options['cas'] = $cas;
        }

        $resp = $this->bucket->unlock($id, $options);

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            return true;
        }

        return false;
    }

    /**
     * @param type $ids
     * @param type $cas
     * @param type $flags
     *
     * @return array the documents indexed by id
     *
     * @throws \Couchbase\Exception
     */
    public function getMulti($ids, &$cas, $flags = 0)
    {
        $options = array();

        $resp = $this->bucket->get($ids, $options);

        if (is_array($resp)) {
            $ret = array();
            $cas = array();
            foreach ($resp as $n => $v) {
                if (is_object($v) && is_null($v->error) && is_resource($v->cas)) {
                    $ret[$n] = $this->unserializeValue($v->value);
                    $cas[$n] = $v->cas;
                }
            }

            return $ret;
        }

        return null;
    }

    /**
     * @param type $id
     * @param type $callback
     * @param type $cas
     *
     * @return object the document requested
     *                null if the document does not exists
     *
     * @throws \Couchbase\Exception
     */
    public function get($id, $callback = null, &$cas = null)
    {
        $options = array();

        try {
            $resp = $this->bucket->get($id, $options);
        } catch (\Couchbase\Exception $e) {
            //      if (strpos($e->getMessage(),'The key does not exist on the server') !== false) {
            if ($e->getCode() == COUCHBASE_KEY_ENOENT) {
                return null;
            }
            throw $e;
        }

        if (is_object($resp) && is_null($resp->error)) {
            $cas = $resp->cas;
            // in some lib version an empty string was returned as value null, only the flag changed
            // 67108864 for an empty string (which gives the type "string" because 67108864 & 31 = 0)
            // 33554433 for null (which gives the type "json" because 33554433 & 31 = 6
            return $resp->value === null && ($resp->flags & 31) == 0 ? '' : $this->unserializeValue($resp->value);
        }

        return null;
    }

    /**
     * @param type $ids
     * @param type $cas
     * @param type $flags
     * @param type $expiry @see class documentation
     *
     * @return array the documents indexed by id
     *
     * @throws \Couchbase\Exception
     */
    public function getAndLockMulti($ids, &$cas, $flags, $expiry = 0)
    {
        $options = array(
            'lockTime' => $expiry,
        );

        $resp = $this->bucket->get($ids, $options);

        if (is_array($resp)) {
            $ret = array();
            $cas = array();
            foreach ($resp as $n => $v) {
                if (is_object($v) && is_null($v->error) && is_resource($v->cas)) {
                    $ret[$n] = $this->unserializeValue($v->value);
                    $cas[$n] = $v->cas;
                }
            }

            return $ret;
        }

        return null;
    }

    /**
     * @param type $id
     * @param type $cas
     * @param type $expiry
     *
     * @return object the requested document
     *                null if the document does not exists
     *
     * @throws \Couchbase\Exception
     */
    public function getAndLock($id, &$cas, $expiry)
    {
        $options = array(
            'lockTime' => $expiry,
        );

        try {
            $resp = $this->bucket->get($id, $options);
        } catch (\Couchbase\Exception $e) {
            if ($e->getCode() == COUCHBASE_KEY_ENOENT) {
                //        error_log(__METHOD__.' : '.$e->getMessage().' ('.$e->getCode().')');
                return null;
            }
            throw $e;
        }

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            $cas = $resp->cas;
            // this case is not supposed to happen anymore (transcoder issue)
            // still we keep it in case, it allows the caller to know that the lock succeeded but
            // the value is not returned
            return $resp->value === null ? false : $this->unserializeValue($resp->value);
        }

        return null;
    }

    /**
     * @param type $ids
     * @param type $expiry @see class documentation
     * @param type $cas
     *
     * @return array the documents indexed by id
     *               null if the document does not exists
     *
     * @throws \Couchbase\Exception
     */
    public function getAndTouchMulti($ids, $expiry, &$cas)
    {
        $options = array(
            'expiry' => $this->transformExpiry($expiry),
        );

        $resp = $this->bucket->get($ids, $options);

        if (is_array($resp)) {
            $ret = array();
            $cas = array();
            foreach ($resp as $n => $v) {
                if (is_object($v) && is_null($v->error) && is_resource($v->cas)) {
                    $ret[$n] = $this->unserializeValue($v->value);
                    $cas[$n] = $v->cas;
                }
            }

            return $ret;
        }

        return null;
    }

    /**
     * @param type $id
     * @param type $expiry @see class documentation
     * @param type $cas
     *
     * @return object the requested document
     *
     * @throws \Couchbase\Exception
     */
    public function getAndTouch($id, $expiry, &$cas)
    {
        $options = array(
            'expiry' => $this->transformExpiry($expiry),
        );

        try {
            $resp = $this->bucket->get($id, $options);
        } catch (\Couchbase\Exception $e) {
            if ($e->getCode() == COUCHBASE_KEY_ENOENT) {
                return null;
            }
            throw $e;
        }

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            $cas = $resp->cas;

            return $this->unserializeValue($resp->value);
        }

        return null;
    }

    /**
     * @param type $id
     * @param type $document
     * @param type $expiry       @see class documentation
     * @param type $cas
     * @param type $persist_to
     * @param type $replicate_to
     *
     * @return string the cas value of the document
     *                null if the document cannot be modified
     *
     * @throws \Couchbase\Exception
     */
    public function set($id, $document, $expiry = null, $cas = null, $persist_to = 0, $replicate_to = 0)
    {
        $options = array();

        $options['expiry'] = $this->transformExpiry($expiry, 0);

        // both must be passed as options or none
        if ($persist_to > 0 || $replicate_to > 0) {
            $options['persist_to'] = $persist_to > 0 ? $persist_to : 0;
            $options['replicate_to'] = $replicate_to > 0 ? $replicate_to : $persist_to - 1;
        }

        if (!empty($cas)) {
            $options['cas'] = $cas;
        }

        $resp = $this->bucket->upsert($id, $this->serializeValue($document), $options);

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            return $resp->cas;
        }

        return null;
    }

    /**
     * @param type $documents
     * @param type $expiry
     * @param type $persist_to
     * @param type $replicate_to
     *
     * @return array id => cas if successful or false if value not modified
     *               null if modification not possible
     *
     * @throws \Couchbase\Exception
     */
    public function setMulti($documents, $expiry = null, $persist_to = 0, $replicate_to = 0)
    {
        $options = array();

        $options['expiry'] = $this->transformExpiry($expiry, 0);

        // both must be passed as options or none
        if ($persist_to > 0 || $replicate_to > 0) {
            $options['persist_to'] = $persist_to > 0 ? $persist_to : 0;
            $options['replicate_to'] = $replicate_to > 0 ? $replicate_to : $persist_to - 1;
        }

        // upsert requires a different syntax than before, so we have to transform the value
        $req = array();
        foreach ($documents as $id => $document) {
            $req[$id] = array('value' => $this->serializeValue($document));
        }

        $resp = $this->bucket->upsert($req, null, $options);
        if (is_array($resp)) {
            $ret = array();
            foreach ($resp as $n => $v) {
                if (is_object($v) && is_null($v->error) && is_resource($v->cas)) {
                    $ret[$n] = $v->cas;
                } else {
                    $ret[$n] = false;
                }
            }

            return $ret;
        }

        return null;
    }

    /**
     * @param type $id
     * @param type $document
     * @param type $expiry       @see class documentation
     * @param type $cas
     * @param type $persist_to
     * @param type $replicate_to
     *
     * @return string the cas value of the document
     *                null if modification not possible (document does not exists)
     *
     * @throws \Couchbase\Exception
     */
    public function replace($id, $document, $expiry = null, $cas = null, $persist_to = 0, $replicate_to = 0)
    {
        $options = array();

        $options['expiry'] = $this->transformExpiry($expiry, 0);

        // both must be passed as options or none
        if ($persist_to > 0 || $replicate_to > 0) {
            $options['persist_to'] = $persist_to > 0 ? $persist_to : 0;
            $options['replicate_to'] = $replicate_to > 0 ? $replicate_to : $persist_to - 1;
        }

        if (!empty($cas)) {
            $options['cas'] = $cas;
        }

        try {
            $resp = $this->bucket->replace($id, $this->serializeValue($document), $options);
        } catch (\Couchbase\Exception $e) {
            if ($e->getCode() == COUCHBASE_KEY_ENOENT) {
                return null;
            }
            throw $e;
        }

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            return $resp->cas;
        }

        return null;
    }

    /**
     * @param type $ids
     * @param type $expiry
     *
     * @return bool true if sucessful
     *
     * @throws \Couchbase\Exception
     */
    public function touchMulti($ids, $expiry)
    {
        $options = array(
            'expiry' => $this->transformExpiry($expiry),
        );

        $resp = $this->bucket->get($ids, $options);

        if (is_array($resp)) {
            return true;
        }

        return false;
    }

    /**
     * @param type $id
     * @param type $expiry @see class documentation
     *
     * @return bool true if sucessful
     *
     * @throws \Couchbase\Exception
     */
    public function touch($id, $expiry)
    {
        $options = array(
            'expiry' => $this->transformExpiry($expiry),
        );

        try {
            $resp = $this->bucket->get($id, $options);
        } catch (\Couchbase\Exception $e) {
            if ($e->getCode() == COUCHBASE_KEY_ENOENT) {
                return false;
            }
            throw $e;
        }

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            return true;
        }

        return false;
    }

    /**
     * @param type $id
     * @param type $delta
     * @param type $create
     * @param type $expiry
     * @param type $initial
     *
     * @return int the new value upon success
     *             null in case of error
     *
     * @throws \Couchbase\Exception
     */
    public function increment($id, $delta, $create, $expiry, $initial)
    {
        $options = array();

        if ($create) {
            // the expiry is useful at creation only
            // if passed when the key exists its value is set to null !!
            if (!is_null($expiry)) {
                $options['expiry'] = $this->transformExpiry($expiry);
            }
            $options['initial'] = $initial;
        }

        $resp = $this->bucket->counter($id, $delta, $options);

        if (is_object($resp) && is_null($resp->error)) {
            return $this->unserializeValue($resp->value);
        }

        return null;
    }

    /**
     * @param type $id
     * @param type $document
     * @param int  $expiry null to never expire
     * @param type $persist_to
     * @param type $replicate_to
     *
     * @return object the cas value of the document
     *                false if the value already exists
     *
     * @throws \Couchbase\Exception
     */
    public function add($id, $document, $expiry = null, $persist_to = 0, $replicate_to = 0)
    {
        $options = array();

        if (!is_null($expiry)) {
            $options['expiry'] = $this->transformExpiry($expiry);
        }

        // both must be passed as options or none
        if ($persist_to > 0 || $replicate_to > 0) {
            $options['persist_to'] = $persist_to > 0 ? $persist_to : 0;
            $options['replicate_to'] = $replicate_to > 0 ? $replicate_to : $persist_to - 1;
        }

        try {
            $resp = $this->bucket->insert($id, $document, $options); /* @var $rep CouchbaseMetaDoc */
        } catch (\Couchbase\Exception $e) {
            //      if (strpos($e->getMessage(), 'The key already exists in the server') !== false) {
            if ($e->getCode() == COUCHBASE_KEY_EEXISTS) {
                return false;
            }
            throw $e;
        }

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            return $resp->cas;
        }

        return null;
    }

    /**
     * @param type $id
     * @param type $cas
     * @param type $persist_to
     * @param type $replicate_to
     *
     * @return string the cas value of the deleted document
     *                false if the value cannot be deleted
     */
    public function delete($id, $cas = null, $persist_to = 1, $replicate_to = 0)
    {
        $options = array();

        if (!empty($cas)) {
            $options['cas'] = $cas;
        }

        if ($persist_to > 0 || $replicate_to > 0) {
            $options['persist_to'] = $persist_to > 0 ? $persist_to : 0;
            $options['replicate_to'] = $replicate_to > 0 ? $replicate_to : $persist_to - 1;
        }

        $resp = $this->bucket->remove($id, $options);

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            return $resp->cas;
        }

        return false;
    }

    /**
     * @param type $id
     * @param type $document
     * @param type $expiry
     * @param type $cas
     * @param type $persist_to
     * @param type $replicate_to
     *
     * @return string the cas value of the document
     *                false if the value cannot be modified
     *
     * @throws \CouchbaseException
     */
    public function append($id, $document, $expiry = null, $cas = null, $persist_to = 0, $replicate_to = 0)
    {
        $options = array();

        // expiry is ignored by the PHP lib !
//    $options['expiry'] = $this->transformExpiry($expiry,0);

        // both must be passed as options or none
        if ($persist_to > 0 || $replicate_to > 0) {
            $options['persist_to'] = $persist_to > 0 ? $persist_to : 0;
            $options['replicate_to'] = $replicate_to > 0 ? $replicate_to : $persist_to - 1;
        }

        if (!empty($cas)) {
            $options['cas'] = $cas;
        }

        try {
            $resp = $this->bucket->append($id, $document, $options);
        } catch (\Couchbase\Exception $e) {
            if ($e->getCode() == COUCHBASE_NOT_STORED) {
                return false;
            }
            throw $e;
        }

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            return $resp->cas;
        }

        return false;
    }

    /**
     * @param type $id
     * @param type $document
     * @param type $expiry
     * @param type $cas
     * @param type $persist_to
     * @param type $replicate_to
     *
     * @return string the cas value of the document
     *                false if the value cannot be modified
     *
     * @throws \Couchbase\Exception
     */
    public function prepend($id, $document, $expiry = null, $cas = null, $persist_to = 0, $replicate_to = 0)
    {
        $options = array();

        // expiry is ignored by the PHP lib !
//    $options['expiry'] = $this->transformExpiry($expiry,0);

        // both must be passed as options or none
        if ($persist_to > 0 || $replicate_to > 0) {
            $options['persist_to'] = $persist_to > 0 ? $persist_to : 0;
            $options['replicate_to'] = $replicate_to > 0 ? $replicate_to : $persist_to - 1;
        }

        if (!empty($cas)) {
            $options['cas'] = $cas;
        }

        try {
            $resp = $this->bucket->prepend($id, $document, $options);
        } catch (\Couchbase\Exception $e) {
            if ($e->getCode() == COUCHBASE_NOT_STORED) {
                return false;
            }
            throw $e;
        }

        if (is_object($resp) && is_null($resp->error) && !is_null($resp->cas)) {
            return $resp->cas;
        }

        return false;
    }

    /**
     * @param type $designDoc
     * @param type $viewName
     * @param type $options    the options understood are a subset of those from the client lib v1 :
     *                         http://www.couchbase.com/autodocs/couchbase-php-client-1.1.5/classes/Couchbase.html#method_view
     *                         startkey, endkey, skip, limit, key, keys, stale, descending, full_set, inclusive_end, connection_timeout
     * @param type $returnErrs
     *
     * @return array an array composed of :
     *               "rows"        : the list of matching entries, see below for the syntax of one entry
     *               "total_rows"  : total number of matching rows
     *               Each entry is an array composed of :
     *               "key"   : index key
     *               "id"    : document id/key
     *               "value" : index value for this key (can be null)
     *
     * @throws \CouchbaseException
     */
    public function view($designDoc, $viewName, $options = array(), $returnErrs = true)
    {
        $query = \Couchbase\ViewQuery::from($designDoc, $viewName);
        $customOpt = array();

        if (isset($options['skip'])) {
            $query->skip($options['skip']);
        }

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        if (isset($options['startkey'])) {
            $customOpt['startkey'] = json_encode($options['startkey']);
        }

        if (isset($options['endkey'])) {
            $customOpt['endkey'] = json_encode($options['endkey']);
        }

        if (isset($options['key'])) {
            $customOpt['key'] = json_encode($options['key']);
        }

        if (isset($options['keys'])) {
            $customOpt['keys'] = json_encode($options['keys']);
        }

        if (isset($options['full_set'])) {
            $customOpt['full_set'] = $options['full_set'] ? 'true' : 'false';
        }

        if (isset($options['descending'])) {
            $customOpt['descending'] = $options['descending'] ? 'true' : 'false';
        }

        if (isset($options['inclusive_end'])) {
            $customOpt['inclusive_end'] = $options['inclusive_end'] ? 'true' : 'false';
        }

        if (isset($options['connection_timeout'])) {
            $customOpt['connection_timeout'] = ''.$options['connection_timeout']; // in microseconds (1/1000000 sec)
        }

        // "stale" was documented like a boolean but it is in fact a mixed value :
        //  (boolean) true  = ok if stale and no update needed
        //  (boolean) false = must update before
        //  (string) "update_after" = ok if stale but update the index after the query
        //  (string) "ok"   = @see case true

        $query->stale(\Couchbase\ViewQuery::UPDATE_NONE);

        if (isset($options['stale'])) {
            if ($options['stale'] === 'update_after') {
                $query->stale(\Couchbase\ViewQuery::UPDATE_AFTER);
            } elseif ($options['stale'] === false) {
                $query->stale(\Couchbase\ViewQuery::UPDATE_BEFORE);
            }
        }

        if (count($customOpt) > 0) {
            $query->custom($customOpt);
        }
//    echo $query->toString()."\n";
        $resp = $this->bucket->query($query, true);

        return [
            'rows' => array_map('get_object_vars', $resp->rows),
            'total_rows' => $resp->total_rows,
        ];
    }

    protected function transformExpiry($expiry, $retWhenNull = null)
    {
        if ($expiry === null || $expiry === false) {
            return $retWhenNull;
        }

        // 0 means "no expiration", we must treat it specifically otherwise a timestamp will be returned
        if ($expiry == 0) {
            return 0;
        }

        $now = time();

        if ($expiry > $now) {
            return $expiry;
        } elseif ($expiry < 86400) { // 1 day
            // we send the number as is so that Couchbase calculates the timestamp
            // it is more precise for a small duration
            return $expiry;
        } else {
            $ts = $now + $expiry;
            // for 32bits systems, the timestamp can be farther than 2038
            return bccomp($ts, PHP_INT_MAX) > 0 ? PHP_INT_MAX : $ts;
        }
    }

    /**
     * @param mixed $value
     *
     * @return array
     */
    protected function serializeValue($value)
    {
        if (
            $this->encoderFormat != 'json' ||
            !is_array($value) &&
            !is_object($value)
        ) {
            return $value;
        }

        return [
            'serialized' => true,
            'data' => serialize($value),
        ];
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected function unserializeValue($value)
    {
        if (
            is_object($value) &&
            !empty($value->serialized)
        ) {
            return unserialize($value->data);
        }
        if (
            is_array($value) &&
            !empty($value['serialized'])
        ) {
            return unserialize($value['data']);
        }

        return $value;
    }
}
