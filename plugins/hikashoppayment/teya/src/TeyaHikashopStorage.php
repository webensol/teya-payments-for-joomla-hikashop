<?php
defined('_JEXEC') or die('Restricted access');

/** Checked persistence. Every financial operation locks method, then order. */
class TeyaHikashopStorage
{
    private $db;
    public function __construct($db = null) { $this->db = $db ?: JFactory::getDBO(); }
    public static function params($raw)
    {
        if ($raw instanceof stdClass) { self::validateData($raw); return clone $raw; }
        if ($raw === null || $raw === '') { return new stdClass(); }
        $value = @unserialize($raw, array('allowed_classes' => array('stdClass')));
        if (!$value instanceof stdClass) { throw new RuntimeException('Invalid stored Teya data.'); }
        self::validateData($value);
        return $value;
    }
    private static function validateData($value,$depth=0)
    {
        if ($depth>64 || (is_object($value) && !$value instanceof stdClass)) { throw new RuntimeException('Invalid nested Teya data.'); }
        if (is_array($value) || is_object($value)) { foreach ((array) $value as $item) { self::validateData($item,$depth+1); } }
    }
    public function locked($name, callable $callback)
    {
        $key = 'teya_hika_' . $name;
        $this->db->setQuery('SELECT GET_LOCK(' . $this->db->quote($key) . ', 5)');
        if ((int) $this->db->loadResult() !== 1) { throw new RuntimeException('Payment is being updated. Please retry shortly.'); }
        try { return $callback(); }
        finally { $this->db->setQuery('SELECT RELEASE_LOCK(' . $this->db->quote($key) . ')'); $this->db->loadResult(); }
    }
    public function method($id)
    {
        $this->db->setQuery('SELECT * FROM #__hikashop_payment WHERE payment_type = ' . $this->db->quote('teya') . ' AND payment_id = ' . (int) $id);
        $row = $this->db->loadObject();
        if (!$row) { throw new RuntimeException('Teya payment method not found.'); }
        $row->_raw = $row->payment_params;
        $row->payment_params = self::params($row->_raw);
        return $row;
    }
    public function saveMethod($row)
    {
        $params = TeyaHikashopCredentials::protect($row->payment_params);
        $raw = serialize($params);
        $this->compareAndSwap('#__hikashop_payment', 'payment_id', $row->payment_id, 'payment_params', $row->_raw, $raw);
        $row->_raw = $raw;
        $row->payment_params = $params;
    }
    public function order($id)
    {
        $this->db->setQuery('SELECT * FROM #__hikashop_order WHERE order_id = ' . (int) $id);
        $row = $this->db->loadObject();
        if (!$row || $row->order_payment_method !== 'teya' || $row->order_type !== 'sale') { throw new RuntimeException('Teya order not found.'); }
        $row->_raw = $row->order_payment_params;
        $row->order_payment_params = self::params($row->_raw);
        return $row;
    }
    private function compareAndSwap($table, $idField, $id, $field, $before, $after)
    {
        if ($before === $after) { return; }
        $this->db->setQuery('UPDATE ' . $table . ' SET ' . $field . ' = ' . $this->db->quote($after)
            . ' WHERE ' . $idField . ' = ' . (int) $id . ' AND ' . $field . ' <=> ' . ($before === null ? 'NULL' : $this->db->quote($before)));
        if (!$this->db->execute() || $this->db->getAffectedRows() !== 1) { throw new RuntimeException('Payment data changed or could not be saved. Please retry.'); }
    }
    public function saveOrder($row, $status = null, $note = '')
    {
        $raw = serialize($row->order_payment_params);
        $this->db->transactionStart();
        try {
            $this->compareAndSwap('#__hikashop_order','order_id',$row->order_id,'order_payment_params',$row->_raw,$raw);
            if ($status !== null || $note !== '') {
                $update = (object) array('order_id'=>$row->order_id, 'history'=>(object) array('history_reason'=>$note,
                    'history_data'=>$note, 'history_type'=>'payment', 'history_payment_method'=>'teya', 'history_notified'=>$status !== null ? 1 : 0));
                if ($status !== null) { $update->order_status = $status; }
                if (!hikashop_get('class.order')->save($update)) { throw new RuntimeException('Could not save HikaShop payment status.'); }
            }
            $this->db->transactionCommit();
            $row->_raw = $raw;
        } catch (Throwable $e) { $this->db->transactionRollback(); throw $e; }
    }
    public function findSession($session, $methodId)
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $session)) { throw new RuntimeException('Invalid session identifier.'); }
        $this->db->setQuery('SELECT order_id FROM #__hikashop_order WHERE order_payment_method = ' . $this->db->quote('teya')
            . ' AND order_payment_id = ' . (int) $methodId . ' AND order_payment_params LIKE ' . $this->db->quote('%' . $this->db->escape($session, true) . '%'));
        foreach ($this->db->loadColumn() as $id) {
            $row = $this->order($id);
            if (($row->order_payment_params->teya_session_id ?? '') === $session) { return (int) $id; }
        }
        throw new RuntimeException('Checkout session not found.');
    }
    public function pending($methodId = null)
    {
        $this->db->setQuery('SELECT order_id FROM #__hikashop_order WHERE order_payment_method = ' . $this->db->quote('teya')
            . ($methodId === null ? '' : ' AND order_payment_id = ' . (int) $methodId)
            . ' AND order_payment_params LIKE ' . $this->db->quote('%teya_refund%') . ' ORDER BY order_id');
        $result = array();
        foreach ($this->db->loadColumn() as $id) {
            $order = $this->order($id);
            $state = (array) ($order->order_payment_params->teya_refunds ?? array());
            if (!empty($state['pending']) || !empty($state['sync_pending']) || ((((array) ($order->order_payment_params->teya_refund_data ?? array()))['status'] ?? '') === 'pending')) { $result[] = $order; }
        }
        return $result;
    }
}
