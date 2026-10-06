<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, Flash, Database, ErrorHandler};
use App\Models\RatingModel;
final class RatingController extends Controller
{
 public function index(): void {
  $this->requireLogin(); $model=new RatingModel();
  $result=$model->paginateRatings(['passenger_id'=>(int)Auth::id()],$this->request->int('page',1));
  $this->view('ratings/index',['title'=>'My ratings - UniGo','pageTitle'=>'My ratings','pageSub'=>'Review completed trips','trips'=>$model->rateableTrips((int)Auth::id()),'ratings'=>$result['items'],'paginator'=>$result['paginator']],'layouts/app');
 }
 public function store(): void {
  $this->requireLogin();$this->verifyCsrf();
  try {
   $result=Database::instance()->transaction(fn () => (new RatingModel())->rate(['trip_id'=>$this->request->int('trip_id'),'rating'=>$this->request->int('rating'),'comment'=>$this->request->str('comment')],(int)Auth::id()));
   Flash::success($result['message']);
  } catch (\App\Core\AppException $e) {Flash::error($e->getMessage());}
  catch (\Throwable $e) {ErrorHandler::log('error','Rating failed: '.$e->getMessage());Flash::error('Your rating could not be saved. Please try again.');}
  $this->redirect('/ratings');
 }
}
