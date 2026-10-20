<?php
final class AiVisibilityController extends Controller
{
    public function data(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($err=$this->verifyCsrf()))return $err;
        try{$uid=(int)$this->user['id'];$wid=(int)$this->get('website_id');$svc=new AiVisibilityService($this->db);$action=$params['action']??'overview';
            $mid=(int)$this->get('market_id');
            $result=match($action){
                'generate'=>$svc->generateDraft($uid,$wid,$mid),
                'questions'=>$svc->save($uid,$wid,$mid,array_values(array_filter(array_map(static fn($q)=>is_string($q)?trim($q):$q,(array)$this->get('questions',[])),static fn($q)=>$q!=='')),(int)$this->get('version'),$this->get('approved')===true),
                'identity'=>$svc->identity($uid,$wid,(array)$this->get('aliases',[]),(array)$this->get('profile_urls',[]),(int)$this->get('identity_version')),
                default=>null
            };return $this->success(AiVisibilityPresentation::customer($svc->customerOverview($uid,$wid)));
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){
            Logger::error('AI Visibility operation unavailable',[
                'action'=>$params['action']??'overview','website_id'=>(int)$this->get('website_id'),
                'user_id'=>(int)$this->user['id'],'exception'=>get_class($e),
                'error'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()
            ]);
            return $this->error('تعذر تحميل النتائج دلوقتي. جرّب مرة تانية أو تواصل مع الدعم.',503);
        }
    }
    public function generate(array $params=[]): array {return $this->data(['action'=>'generate']);}
    public function questions(array $params=[]): array {return $this->data(['action'=>'questions']);}
    public function identity(array $params=[]): array {return $this->data(['action'=>'identity']);}
    public function llms(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($err=$this->verifyCsrf())return $err;
        try{$r=(new AiVisibilityService($this->db))->llmsDraft((int)$this->user['id'],(int)$this->get('website_id'));return $this->success(['draft'=>$r['draft'],'status'=>'draft_requires_review','note'=>'راجع البيانات قبل النشر. الملف ده بيساعد محركات الذكاء الاصطناعي تفهم موقعك، ومفيش ضمان ترتيب.']);}
        catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){Logger::error('AI Visibility llms draft failed',['website_id'=>(int)$this->get('website_id'),'error'=>$e->getMessage()]);return $this->error('تعذر قراءة صفحات موقعك العامة دلوقتي. اتأكد إن الموقع شغال وجرّب تاني.',422);}
    }
    /** Compatibility with deployed web routes that still dispatch to index. */
    public function index(array $params=[]): array {return $this->page($params);}
    public function page(array $params=[]): array {if(!$this->isAuthenticated())return $this->error('Unauthorized',401);ob_start();require dirname(__DIR__).'/Views/AiVisibility/index.php';$body=ob_get_clean();echo $this->renderPanelPage('ai_analyze','ظهورك في الذكاء الصناعي','قياسات فعلية للأسئلة والمصادر، بدون تقديرات',$body);return [];}
}
