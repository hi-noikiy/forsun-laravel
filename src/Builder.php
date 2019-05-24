<?php
/**
 * Created by PhpStorm.
 * User: snower
 * Date: 18/3/6
 * Time: 下午3:23
 */

namespace Snower\LaravelForsun;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Symfony\Component\Process\ProcessUtils;
use Snower\LaravelForsun\Jobs\CommandRunHandler;
use Snower\LaravelForsun\Jobs\EventFireHandler;
use Snower\LaravelForsun\Jobs\JobDispatchHandler;

class Builder
{
    protected $forsun;
    protected $name;

    use ManagesFrequencies;

    public function __construct($forsun, $name)
    {
        $this->forsun = $forsun;
        $this->name = $name;
        $this->second = 1;
        $this->minute = 0;
        $this->hour = 0;
        $this->day = 0;
        $this->month = 0;
        $this->week  = 0;
        $this->is_timeout = true;
        $this->timeout_count = 1;
        $this->action = '';
        $this->params = [];
        $this->queue_name = '';

        $this->timed = false;
        $this->paramsed = false;
        $this->scheduled = false;

        if(empty($this->name)){
            $this->genName();
        }else{
            $this->name = config('forsun.prefix') . $this->name;
        }
    }

    protected function genName(){
        $this->name = config('forsun.prefix') . Carbon::now()->format("YmdHis") . str_random(26);
    }

    protected function initAction(){
        $database_config = config("database");
        $queue_config = config("queue");

        if($queue_config["default"] !== 'redis' && $queue_config["default"] !== 'database' && $queue_config["default"] !== 'beanstalkd'){
            throw new UnsupportedQueueException();
        }

        if($queue_config["default"] === 'database'){
            $this->action = 'mysql';

            $connection = Arr::get(Arr::get($queue_config["connections"], 'database', []), 'connection', Arr::get($database_config, 'default', 'default'));
            $database_config = Arr::get($database_config['connections'], $connection, []);
            $this->params = [
                'host' => Arr::get($database_config, 'host', '127.0.0.1'),
                'port' => strval(Arr::get($database_config, 'port', 3306)),
                'db' => Arr::get($database_config, 'database', 'mysql'),
                'user' => Arr::get($database_config, 'user', 'root'),
                'passwd' => Arr::get($database_config, 'password', ''),
                'table' => Arr::get($queue_config["connections"]["database"], 'table', 'jobs'),
            ];
            $this->queue_name = Arr::get($queue_config["connections"]["database"], 'queue', 'default');
        }else if($queue_config["default"] === 'beanstalkd'){
            $this->action = 'beanstalk';

            $this->params = [
                'host' => Arr::get($queue_config["connections"]["beanstalkd"], 'host', '127.0.0.1'),
                'port' => strval(Arr::get($queue_config["connections"]["beanstalkd"], 'port', 11300)),
                'name' => Arr::get($queue_config["connections"]["beanstalkd"], 'queue', 'default'),
            ];
            $this->queue_name = Arr::get($queue_config["connections"]["beanstalkd"], 'queue', 'default');
        }else{
            $this->action = 'redis';

            $connection = Arr::get(Arr::get($queue_config["connections"], 'redis', []), 'connection', Arr::get($database_config, 'default', 'default'));
            $database_config = Arr::get($database_config['redis'], $connection, []);
            $this->params = [
                'host' => Arr::get($database_config, 'host', '127.0.0.1'),
                'port' => strval(Arr::get($database_config, 'port', 6379)),
                'selected_db' => strval(Arr::get($database_config, 'database', 0)),
            ];

            $password = strval(Arr::get($database_config, 'password', null));
            if($password !== '' && $password !== null){
                $this->params['password'] = $password;
            }
            $this->queue_name = Arr::get($queue_config["connections"]["redis"], 'queue', 'default');
        }
    }

    /**
     * Add a new Artisan command event to the schedule.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return \Illuminate\Console\Scheduling\Event
     */
    public function command($command, array $parameters = [])
    {
        if (class_exists($command)) {
            $command = Container::getInstance()->make($command)->getName();
        }

        return $this->exec(
            Application::formatCommandString($command), $parameters
        );
    }

    /**
     * Add a new job callback event to the schedule.
     *
     * @param  object|string  $job
     * @return \Illuminate\Console\Scheduling\CallbackEvent
     */
    public function job($job)
    {
        $this->initAction();
        $this->createPayload(new JobDispatchHandler($job));
        return $this->schedule();
    }

    /**
     * Add a new command event to the schedule.
     *
     * @param  string  $command
     * @param  array  $parameters
     * @return \Illuminate\Console\Scheduling\Event
     */
    public function exec($command, array $parameters = [])
    {
        $this->initAction();
        if (count($parameters)) {
            $command .= ' '.$this->compileParameters($parameters);
        }

        $this->createPayload(new CommandRunHandler($command));
        return $this->schedule();
    }

    public function fire($event, $payload = [], $halt = false){
        $this->initAction();
        $this->createPayload(new EventFireHandler($event, $payload, $halt));
        return $this->schedule();
    }

    public function http($url, $method = "GET", $body = '', $headers = [], $options = []){
        $this->action = "http";
        $this->params = [
            'url' => $url,
            'method' => $method,
            'body' => $body,
        ];

        foreach ($headers as $key => $value){
            $this->params['header_' . $key] = $value;
        }

        $option_keys = ['auth_username', 'auth_password', 'auth_mode', 'user_agent', 'connect_timeout', 'request_timeout'];
        foreach ($option_keys as $key){
            if(isset($options[$key])){
                $this->params[$key] = $options[$key];
            }
        }
        $this->paramsed = true;
        return $this->schedule();
    }

    protected function compileParameters(array $parameters)
    {
        return collect($parameters)->map(function ($value, $key) {
            if (is_array($value)) {
                $value = collect($value)->map(function ($value) {
                    return ProcessUtils::escapeArgument($value);
                })->implode(' ');
            } elseif (! is_numeric($value) && ! preg_match('/^(-.$|--.*)/i', $value)) {
                $value = ProcessUtils::escapeArgument($value);
            }

            return is_numeric($key) ? $value : "{$key}={$value}";
        })->implode(' ');
    }

    protected function createPayload($command){
        $data = [
            "job" => "Illuminate\\Queue\\CallQueuedHandler@call",
            'displayName' => "Illuminate\\Queue\\CallQueuedHandler",
            'maxTries' => null,
            'timeout' => null,
            "data" => [
                "commandName" => get_class($command),
                "command" => serialize($command)
            ]
        ];

        if($this->action == 'redis') {
            $data['id'] = Str::random(32);
            $data['attempts'] = 0;
        }
        $data = json_encode($data);

        if($this->action === 'mysql') {
            $now = Carbon::now()->getTimestamp();
            $table = $this->params["table"];
            $data = addslashes($data);
            $this->params['sql'] = "INSERT INTO `{$table}` (`queue`,`payload`,`attempts`,`reserved`,`reserved_at`,`available_at`,`created_at`) VALUES ('{$this->queue_name}','{$data}',0,0,0,{$now},{$now})";
        }
        else if($this->action === 'beanstalk') {
            $this->params['body'] = $data;
        }
        else{
            $data = addcslashes($data, '\'');
            $this->params['command'] = "RPUSH 'queues:{$this->queue_name}' '{$data}'";
        }

        $this->paramsed = true;
    }

    public function schedule(){
        if($this->timed && $this->paramsed){
            if($this->is_timeout){
                $plan = $this->forsun->createTimeout($this->name, $this->second, $this->minute, $this->hour, $this->day, $this->month, $this->week, $this->timeout_count, $this->action, $this->params);
            }else{
                $plan = $this->forsun->create($this->name, $this->second, $this->minute, $this->hour, $this->day, $this->month, $this->week, $this->action, $this->params);
            }
            $this->scheduled = true;
            return $plan;
        }
        return $this;
    }
}