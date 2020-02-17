# madx-pusher
Pusher service by PHP7 + Swoole

Introduction
Thanks to swoole's websocket wrapper makes this project complete in short time.

How does pusher helps web application and micro services
In high traffic system, most of us ever suffer from a slow process that could lag down the entire web server, this happens due to speed of opening new connection is faster than connection closing. Therefore with pusher, we could build up multi tier architeture in one single server where heavy process we could pull out from our frontend scripting and put the task into waiting list and later on executed by some scheduled tool such as cron job. Finally, the result of execution will then notify user via pusher.

Some programmer prefers to use database locking mechanism to prevent other access the same table and spoil sequences of sql execution. in high traffic system, heavy snatch of lock privillege will waste alot of cpu usages. What come worst is frequent use of lock will complicate your development and occurrence of deadlock will eventually ruin down your application. Solution has been mentioned in previous paragraph. In addition, running task one after one will ensure sequences of sql execution.

How it works
Firstly, client side must request a token which comprises access id and secret seed from server side via HTTPS protocol by filling in correct api credentials. Token's lifetime can stay alive up to maximum 2 hours without any activity. Secret seed is for input of generation of TOTP (Time-based One-time Password Algorithm, specified in RFC 6238). Access id is nothing but identity of this access.

Everytime client opens websocket connection by applying the same secret seed and regenerate TOTP as long as that token is still valid. This practise ensures that client need not always call HTTP to request new token thus reduce time to wait for http response. A opened/used connection string is not allow to use more than once, this protects API owner in case wss link leaks accidentally.
