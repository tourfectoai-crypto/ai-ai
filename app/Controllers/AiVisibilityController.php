<?php
final class AiVisibilityController extends Controller
{
    public function data(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($err=$this->verifyCsrf()))return $err;
        try{$uid=(int)$this->user['id'];$wid=(int)$this->get('website_id');$svc=new AiVisibilityService($this->db);$action=$params['action']??'overview';
            $result=match($action){
                'generate'=>$svc->generate($uid,$wid,(int)$this->get('market_id')),
                'question'=>$svc->saveQuestion($uid,$wid,(int)$this->get('id'),trim((string)$this->get('question')),$this->get('approved')===true),
                'identity'=>$svc->identity($uid,$wid,(array)$this->get('aliases',[]),(array)$this->get('profile_urls',[])),
                default=>$svc->overview($uid,$wid)
            };return $this->success(AiVisibilityPresentation::customer($result));
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
    public function question(array $params=[]): array {return $this->data(['action'=>'question']);}
    public function identity(array $params=[]): array {return $this->data(['action'=>'identity']);}
    public function llms(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        try{$site=(new SeoMarketService($this->db))->owned((int)$this->get('website_id'),(int)$this->user['id']);$ctx=(new SeoExpertPageContext())->fresh($site['main_url']);if(empty($ctx['ok']))throw new RuntimeException('page_unavailable');return $this->success(['draft'=>(new LlmsSiteSummary())->fromContext($ctx),'status'=>'draft_requires_review','note'=>'راجع البيانات؛ النشر من خلال دورة الموصل الحالية مع موافقة وتحقق وإمكانية رجوع. مفيش ضمان ترتيب.']);}catch(Throwable $e){return $this->error('تعذر قراءة الصفحات العامة',422);}
    }
    /** Compatibility with deployed web routes that still dispatch to index. */
    public function index(array $params=[]): array {return $this->page($params);}
    public function page(array $params=[]): array {if(!$this->isAuthenticated())return $this->error('Unauthorized',401);ob_start();require dirname(__DIR__).'/Views/AiVisibility/index.php';$body=ob_get_clean();echo $this->renderPanelPage('ai_analyze','ظهورك في الذكاء الصناعي','قياسات فعلية للأسئلة والمصادر، بدون تقديرات',$body);return [];}
}
