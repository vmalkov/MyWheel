<?
namespace MyWheel;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use League\Container\Container;
use League\Container\ReflectionContainer;

define('DS',DIRECTORY_SEPARATOR);


/*
 * класс для создания объекта-конфигурации
 * например, Config $config->ini_section->ini_param будет содержать
 * значение параметра ini_param в секции ini_section файла конфига .ini
*/
class Config {
	
	private function makeObj($params) {
		foreach($params as $k=>$param) if(empty($this->$k)) {$this->$k = (object)$param; }
	}

	function loadConfig($filepath) {
		$filepath = realpath($filepath);
		if(is_file($filepath)) {
			if(pathinfo($filepath, PATHINFO_EXTENSION)=='ini') $params = parse_ini_file($filepath, true);
			else include_once $filepath;
			if(isset($params)) $this->makeObj($params);
		}
	}

}

abstract class Renderer {
	protected $engine;
    abstract public function render($template, $data = []);

    public function getEngine() {
        return $this->engine;
    }

    public function setEngine($engine) {
        $this->engine = $engine;
    }
}

//интерфейс контроллера
interface Controller {
	//класс контроллера должен содержать по крайней мере метод indexAction - экшн по-умолчанию (index)
	public function indexAction($params);

}


function Go($configFile) {

	$loader = require __DIR__ . '/vendor/autoload.php';

	error_reporting(E_ALL);

	$environment = 'development';

	/**
	* Регистрируем обработчик ошибок
	*/
	$whoops = new \Whoops\Run;
	//в продакшене полную инфу ошибки не выводим
	if ($environment !== 'production') {
	    $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
	} else {
	    $whoops->pushHandler(function($e){
	        echo 'Todo: Красивая заглушка для ошибок и уведомление разраба';
	    });
	}
	$whoops->register();

	

	$container = new Container();
	
	$container
	    ->delegate(
	        // автопривязка на основе constructor typehints.
	        // http://container.thephpleague.com/auto-wiring
	        new ReflectionContainer()
	    );
	
	//$container->share('Container',$container);
	$response = new Response;

	$container->share('Symfony\Component\HttpFoundation\Response',$response);

	$request = Request::createFromGlobals();

	$container->share('Symfony\Component\HttpFoundation\Request',$request);

	//это для определения __APP_PATH; подразумевается, что конфиги в корне приложения

	define ('__APP_PATH',  dirname(realpath($configFile)));
	$config = new Config();
		
	$config->loadConfig($configFile);

	$container->share('MyWheel\Config',$config);

	$container->share('League\Container\Container',$container);


	$appNamespace = basename(__APP_PATH);

	$loader->addPsr4($appNamespace.'\\', __APP_PATH);

	
	$routeDefinitionCallback = function (\FastRoute\RouteCollector $r) use ($container) {
		

    	$routes = include(__APP_PATH.DS.'Routes.php');
	    foreach ($routes as $route) {
	        $r->addRoute($route[0], $route[1], $route[2]);
	    }
	    $container->share('FastRoute\RouteCollector',$r);

	};

	$dispatcher = \FastRoute\simpleDispatcher($routeDefinitionCallback);

	$container->share('dispatcher', $dispatcher);

	$routeInfo = $dispatcher->dispatch($request->getMethod(), $request->getPathInfo());

	$container->share('routeInfo', $routeInfo);
	
	switch ($routeInfo[0]) {
	    case \FastRoute\Dispatcher::NOT_FOUND:
	        $response->setContent('404 - Page not found');
	        $response->setStatusCode(404);
	        break;
	    case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
	        $response->setContent('405 - Method not allowed');
	        $response->setStatusCode(405);
	        break;
	    case \FastRoute\Dispatcher::FOUND:
		    // Fully qualified class name of the controller
	        $fqcn = $routeInfo[1][0];
	        // Controller method responsible for handling the request
	        $routeMethod = $routeInfo[1][1];
	        // Route parameters (ex. /products/{category}/{id})
	        $routeParams = $routeInfo[2];
	        // Obtain an instance of route's controller
	        // Resolves constructor dependencies using the container
	        $controller = $container->get($fqcn);//->addArgument($container);
	        // Generate a response by invoking the appropriate route method in the controller
	        $controller->$routeMethod($routeParams);
		    break;
	}

	$response->prepare($request);
	$response->send();
}