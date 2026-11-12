<?php
function fake_event($type, $text)
{
    fake_pdo()->prepare('INSERT INTO testlog (type, text) VALUES (?, ?)')->execute(array($type, (string) $text));
}

function logActivity($message)
{
    fake_event('activity', $message);
}

function logTransaction($gateway, $data, $result)
{
    fake_event('gwlog', $result);
}
