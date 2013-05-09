<?php
use Respect\Validation\Validator as v;

class UserController extends Controller
{
	public function me()
	{
		$this->render('user/dashboard');
	}

	/**
	 *
	 * @param unknown $id
	 */
	public function tasks($id=-1)
	{
		/* @var $u Users */
		$u = Model::factory('Users')->find_one($this->user['id']);
		if ($u===false)
		{
			$this->app->flash("error", "Cannot find the user data");
			$this->redirect('me.home');
		}

		/* @var $g Group */
		$g = $u->group()->find_one();
		if ($g===false)
		{
			$this->app->flash("error", "Cannot find the group data");
			$this->redirect('me.home');
		}

		if ($id===-1)
			$t = $g->tasks()->find_array();
		else
		{
			$ap = $g->tasks()->find_one($id);
			if ($ap===false)
			{
				$this->app->flash("error", "Cannot find the task data");
				$this->app->notFound();
			}
			$t[] = $ap->as_array();
		}
		if ($id===-1)
			$this->app->flashNow("info", "This is the page for all your assignments");
		else
			$this->app->flashNow("info", "This is the page for your <b>" . $t[0]['name'] . "</b> assignment");

		$this->render('user/tasks',array(
				'group' => $g->as_array(),
				'tasks' => $t
		));

		//var_dump($u->as_array(),$g->as_array());
		//var_dump($t);
	}

	/**
	 *
	 * @param unknown $taskId
	 * @param string $dradtId
	 */
	public function drafts($taskId,$dradtId=null)
	{
		/* @var $u Users */
		$u = Model::factory('Users')->find_one($this->user['id']);
		if ($u===false)
		{
			$this->app->flash("error", "Cannot find the user data");
			$this->redirect('me.home');
		}
		/* @var $g Group */
		$g = $u->group()->find_one();
		if ($g===false)
		{
			$this->app->flash("error", "Cannot find the group data");
			$this->redirect('me.home');
		}

		$d = $u->drafts()->where_equal('task_id',$taskId)->order_by_desc('id')->find_array();
		foreach ($d as $key => &$draft)
		{
			$analysis = json_decode($draft['analysis'],true);
			$wordcount = $analysis['se_stats']['number_of_words'];
			$draft['wordcount'] = $wordcount;
			$k = $analysis['nvl_data'];
			if (isset($k))
				$draft['keywords'] = array_merge($k['quadgrams'],$k['trigrams'],$k['bigrams']);
			//var_dump($draft['keywords']);
			unset($draft['analysis']);
			$gg = $this->timeSince(strtotime($draft['date']));
			$draft['datesince'] = $gg;
		}
		/* @var $d Draft */
		$ap = $g->tasks()->find_one($taskId);
			
		$this->render('user/task.info',array(
				'group' => $g->as_array(),
				'task' => $ap->as_array(),
				'drafts' => $d
		));
		//var_dump($d);
		//date_default_timezone_set('Europe/London');
		//var_dump(date('Y-m-d H:i:s e'));
	}

	public function submitDraft($taskId)
	{
		$req = $this->app->request();
		$async = $this->app->config('openEssayist.async');

		/* @var $d Draft */
		$ap = Model::factory('Task')->find_one($taskId);

		if ($req && $req->isPost())
		{
			$post = $req->post();

			if ($async)
				$this->submitAsync($ap, $taskId, $post);
			else
			{
				try {
					$url = 'http://localhost:8062/api/analysis';
					$request = Requests::post($url,
							array(),
							array('text' => $post["text"]),
							array('timeout' => 30));
					//var_dump($request->status_code);
					if ($request->status_code === 200)
					{
						//var_dump($request->body);
							
						$json = $request->body;
						$ret = json_decode($json,true);
						//var_dump(array_keys($ret));
						//var_dump($ret['ke_data']['bigram_keyphrases']);
							
						/* @var $draft Draft */
						$draft = Model::factory('Draft')->create();
						$draft->type = 0;
						$draft->analysis = $json;
						$draft->task_id = $taskId;
						$draft->users_id = $this->user['id'];
						$draft->date = date('Y-m-d H:i:s e');
						$draft->save();

					}
				}
				catch (Requests_Exception $e)
				{
					$this->app->flashNow("error", "Cannot connect to the analyser (" . $url.")! Try again later");
					//var_dump($e);
				}
				catch (\PDOException  $e)
				{
					$this->app->flashNow("error", "Problem with the database.");
					var_dump($e);

				}
				//$r= $this->app->urlFor('me.drafts',array("idt" => $taskId));
				//$this->redirect($r,false);
			}
		}

		$this->render('user/draft.submit',array(
				'task' => $ap->as_array(),
		));
	}

	private function submitAsync($ap,$taskId,$post)
	{
		$r= $this->app->urlFor('me.draft.process',array("idt" => $taskId));

		$req = $this->app->request();
		$root = $req->getRootUri();
		if ($root == '') $r = "http://localhost:8080".$r;
		try {

			$draft = Model::factory('Draft')->create();
			$draft->type = 0;
			$draft->processed = 0;
			$draft->analysis = $post["text"];
			$draft->task_id = $taskId;
			$draft->users_id = $this->user['id'];
			$draft->date = date('Y-m-d H:i:s e');
			$draft->save();

			$request = Requests::post($r,
					array(),
					array(
							'text' => $post["text"],
							'users_id' => $this->user['id'],
							'task_id' => $taskId,
							'draft_id' => $draft->id
					),
					array('timeout' => 1)
			);
		}
		catch (Requests_Exception $e)
		{
			$this->app->flashNow("info", "Analysis in progress....");
		}
		catch (Exception $e)
		{
			$this->app->flashNow("error", $e);
		}
		$this->render('user/draft.submit',array(
				'task' => $ap->as_array(),
				'text' => $post["text"]
		));

	}

	public function processDraft($taskId)
	{
		ignore_user_abort(true);
		$log = $this->app->getLog();

		$log->info("START PROCESS");

		$req = $this->app->request();
		if ($req && $req->isPost())
		{
			$post = $req->post();
			$log->info(json_encode($post));
			//echo $post["text"];

			/* @var $draft Draft */
			$draft = Model::factory('Draft')->find_one($post['draft_id']);
				
			try {
				$data = $draft->as_array();
				$log->info(json_encode($data));
				sleep(2);

				$url = 'http://localhost:8062/api/analysis';
				$request = Requests::post($url,
						array(),
						array('text' => $post["text"]),
						array('timeout' => 100));

				$log->error("==> " . $request->status_code);
				if ($request->status_code === 200)
				{
					$json = $request->body;
					$ret = json_decode($json,true);
					$draft->analysis = $json;
					$draft->processed = 1;
					$draft->date = date('Y-m-d H:i:s e');
					$draft->save();
					$log->info("ANALYSIS COMPLETED");
				}
				else
				{
					$draft->processed = -1;
					$draft->save();
					$log->info("ANALYSIS FAILED");
				}

			} catch (Exception $e)
			{
				$log->error("==> ERROR");
				$draft->processed = -1;
				$draft->save();
				//$draft->delete();
			}
				
		}
		//$this->redirect('me.home');
		$log->info("STOP PROCESS");

	}

	/**
	 *
	 * @param string $draft
	 * @return Draft
	 */
	protected function getDraft($draft)
	{
		/* @var $u Users */
		$u = Model::factory('Users')->find_one($this->user['id']);
		if ($u===false)
		{
			$this->app->flash("error", "Cannot find the user data");
			$this->redirect('me.home');
		}

		/* @var $g Draft */
		$g = $u->drafts()->find_one($draft);
		if ($g===false)
		{
			$this->app->flash("error", "Cannot find the user data");
			$this->redirect('me.home');
		}
		return $g;

	}

	/**
	 *
	 * @param string $draft
	 */
	public function showDraft($draft)
	{
		$dr = $this->getDraft($draft);
		$tsk = $dr->task()->find_one();


		$data = $dr->as_array();
		$parasenttok = $dr->getParasenttok();
		$analysis = $dr->getAnalysis();
		if (isset($analysis))
		{
			$nagrams = $analysis->nvl_data->quadgrams;
			$nagrams = array_merge($nagrams,$analysis->nvl_data->trigrams);
			$nagrams = array_merge($nagrams,$analysis->nvl_data->bigrams);
		}
		$this->render('drafts/draft.show',array(
				'task' => $tsk->as_array(),
				'draft' => $dr	->as_array(),
				'parasenttok' => $parasenttok,
				'keywords' => $analysis->nvl_data->keywords,
				'ngrams' => $nagrams
		));
	}

	/**
	 *
	 * @param string $draft
	 */
	public function showSentence($draft)
	{
		$dr = $this->getDraft($draft);
		$tsk = $dr->task()->find_one();

		$parasenttok = $dr->getParasenttok();
		$data = $dr->as_array();
		$analysis = $dr->getAnalysis();


		// unpack senteces from structure
		$parasenttok = call_user_func_array('array_merge', $parasenttok);
		$sort = array();
		// extract ranks as key and remove sentence wihthout
		foreach($parasenttok as $k=>&$v)
		{
			if (isset($v['rank']))
				$sort['rank'][$k] = $v['rank'];
			else
				unset($parasenttok[$k]);
		}
		// sort remaining sentences accordingly
		array_multisort($sort['rank'], SORT_ASC, $parasenttok);

		$this->render('drafts/draft.sentence',array(
				'task' => $tsk->as_array(),
				'draft' => $dr->as_array(),
				'sentences' => $parasenttok
		));

		//var_dump($parasenttok);
	}

	/**
	 *
	 * @param string $draft
	 */
	public function showKeyword($draft)
	{
		$dr = $this->getDraft($draft);
		$tsk = $dr->task()->find_one();

		$data = $dr->as_array();
		$analysis = $dr->getAnalysis();


		$this->render('drafts/draft.keyword',array(
				'task' => $tsk->as_array(),
				'draft' => $dr	->as_array(),
				'keywords' => array(
						'quadgrams' => $analysis->nvl_data->quadgrams,
						'trigrams' => $analysis->nvl_data->trigrams,
						'bigrams' => $analysis->nvl_data->bigrams,
						'keylemmas' => $analysis->nvl_data->keywords,
				)
		));
	}

	public function showStats($draft)
	{
		$dr = $this->getDraft($draft);
		$tsk = $dr->task()->find_one();

		$tttt= $dr->getAnalysis(true);
		unset($tttt['parasenttok']);
		unset($tttt['se_data']);
		$body = $tttt['body'];
		$intro = $tttt['intro'];
		$concl = $tttt['concl'];
		$refs = $tttt['refs'];
		$appendix = $tttt['appendix'];
		unset($tttt['body']);
		unset($tttt['intro']);
		unset($tttt['concl']);
		unset($tttt['refs']);
		unset($tttt['appendix']);
		unset($tttt['nvl_data']);
		$struct = array();
		$struct['intro'] = $intro;
		$struct['concl'] = $concl;
		$struct['body'] = $body;
		$struct['refs'] = $refs;
		$struct['appendix'] = $appendix;
		$tttt['structure'] = $struct;

		foreach ($tttt['ke_data'] as $key => $value)
		{
			if (!is_array($value))
			{
				$tttt['ke_stats'][$key] = $value;
			}
		}
		unset($tttt['ke_data']);
		$this->render('drafts/stats',array(
				'task' => $tsk->as_array(),
				'draft' => $dr->as_array(),
				'stats' => $tttt
		));
	}

	public function actionKeyword($draft)
	{
		$dr = $this->getDraft($draft);
		$tsk = $dr->task()->find_one();
		
		$tt = $dr->kwCategories()->find_array();
		$analysis = $dr->getAnalysis();
		
		//$cat = Model::factory('KWCategory')->create();
		//$cat->draft_id = $dr->id;
		//$cat->category = "THIS IS A TEST";
		//$cat->save();
		
		$allkw = array_merge(array(),
			$analysis->nvl_data->quadgrams,
			$analysis->nvl_data->trigrams,
			$analysis->nvl_data->bigrams,
			$analysis->nvl_data->keywords
		);
		
		$this->render('drafts/action.keyword',array(
				'task' => $tsk->as_array(),
				'draft' => $dr->as_array(),
				'keywords' => $allkw
		));
		
		//var_dump($tt);
	}

	public function viewGraph($draft,$graph=null)
	{
		$graphlist = array(
				'graphse' => array(
						'name'	=> 'Sentence network',
						'path'	=> 'drafts/view.graph',
						'data'	=> 'se_sample_graph'),
				'graphke' => array(
						'name'	=> 'Keyword network',
						'path'	=> 'drafts/view.graph',
						'data'	=> 'ke_sample_graph'),
		);

		if (!isset($graph))
		{
			$this->render('drafts/view.graph');
			return;
		}

		if (!array_key_exists($graph,$graphlist))
		{
			$this->app->flash("error", "This view does not exist. Try one of the following.");
			//$this->redirect('me.home');
			$url = $this->app->urlFor('me.draft.view.graph',array('draft'=>$draft));
			var_dump($url);

			$this->app->redirect($url);
			return;
		}

		$path = $graphlist[$graph]['path'];
		$data = $graphlist[$graph]['data'];

		$dr = $this->getDraft($draft);
		$tsk = $dr->task()->find_one();

		$analysis = $dr->getAnalysis(true);
		$gr = json_decode($analysis[$data],true);

		$this->render($path,array(
				'task' => $tsk->as_array(),
				'draft' => $dr->as_array(),
				'views' => $graphlist,
				'view' => $graph,
				'graph' => $gr
		));
	}

	public function saveKeywords()
	{
		$req = $this->app->request();
		$post = null;
		if ($req && $req->isPost())
			$post = $req->post();
	
		sleep(10);
		//var_dump( $post);
		header("Content-Type: application/json");
		echo json_encode($post);
		
	}

	
	public function getExhibitJSON($draftid)
	{
		$json = array('items'=> array());

		$dr = $this->getDraft($draftid);
		$tsk = $dr->task()->find_one();
		
		date_default_timezone_set('UTC');
		
		$date = new DateTime();
		$inc = $date;
		$data = $dr->as_array();
		$parasenttok = $dr->getParasenttok();
		$analysis = $dr->getAnalysis();
		foreach ($parasenttok as $key=>$tt)
		{
			$par = array(
					'label' => 'paragraph'.$key,
					'type' => 'paragraph',
					'start' => $inc->format('M d Y'),
					'sentence' => array()
			);
			foreach ($tt as $key=>$hh)
			{
				$inc->modify('+1 day');
				$sen = array(
						'label' => $hh['id'],
						'type' => 'sentence',
						'tag' => $hh['tag'],
						'start' => $inc->format('M d Y'),
						'text' => ''//$hh['text']
				);
				
				
				$par['sentence'][]=$hh['id'];
				$json['items'][]=$sen;
			}
			$json['items'][]=$par;
		}
		
		$response = $this->app->response();
		$response['Content-Type'] = 'application/json';
		$response['X-Powered-By'] = 'openEssayist';
		$response->status(200);
		
		//$response->body(json_encode($parasenttok));
		$response->body(json_encode($json));
		
	}
	
}
