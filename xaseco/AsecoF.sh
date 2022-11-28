#!/bin/sh
cd /home/my-server2/xaseco
php aseco.php TMF </dev/null >aseco.log 2>&1 &
echo $!
