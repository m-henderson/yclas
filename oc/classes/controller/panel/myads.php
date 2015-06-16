<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Panel_Myads extends Auth_Frontcontroller {

    
	public function action_index()
	{
		$cat = new Model_Category();
		$list_cat = $cat->find_all(); // get all to print at sidebar view
		
		$loc = new Model_Location();
		$list_loc = $loc->find_all(); // get all to print at sidebar view

		$user = Auth::instance()->get_user();
		$ads = new Model_Ad();

		Controller::$full_width = TRUE;

		$my_adverts = $ads->where('id_user', '=', $user->id_user);

		$res_count = $my_adverts->count_all();
		
		if ($res_count > 0)
		{

			$pagination = Pagination::factory(array(
                    'view'           	=> 'oc-panel/crud/pagination',
                    'total_items'    	=> $res_count,
                    'items_per_page' 	=> core::config('advertisement.advertisements_per_page')
     	    ))->route_params(array(
                    'controller' 		=> $this->request->controller(),
                    'action'      		=> $this->request->action(),
                 
    	    ));

    	    Breadcrumbs::add(Breadcrumb::factory()->set_title(__("My Advertisement page ").$pagination->current_page));
    	    $ads = $my_adverts->order_by('published','desc')
                	            ->limit($pagination->items_per_page)
                	            ->offset($pagination->offset)
                	            ->find_all();


          	$this->template->content = View::factory('oc-panel/profile/ads', array('ads'=>$ads,
          																		   'pagination'=>$pagination,
          																		   'category'=>$list_cat,
          																		   'location'=>$list_loc,
          																		   'user'=>$user));
        }
        else
        {

        	$this->template->content = View::factory('oc-panel/profile/ads', array('ads'=>$ads,
          																		   'pagination'=>NULL,
          																		   'category'=>NULL,
          																		   'location'=>NULL,
          																		   'user'=>$user));
        }
	}

	/**
	 * Mark advertisement as deactivated : STATUS = 50
	 */
	public function action_deactivate()
	{

		$id = $this->request->param('id');
		
		
		if (isset($id))
		{

			$deact_ad = new Model_Ad($id);

			if ($deact_ad->loaded())
			{
				if(Auth::instance()->get_user()->id_user != $deact_ad->id_user AND 
				   Auth::instance()->get_user()->id_role != Model_Role::ROLE_ADMIN)

                {
                    Alert::set(Alert::ALERT, __("This is not your advertisement."));
                    HTTP::redirect(Route::url('oc-panel',array('controller'=>'myads','action'=>'index')));
                }
                elseif ($deact_ad->status != Model_Ad::STATUS_UNAVAILABLE)
				{
					$deact_ad->status = Model_Ad::STATUS_UNAVAILABLE;
					
					try
					{
						$deact_ad->save();
					}
						catch (Exception $e)
					{
						throw HTTP_Exception::factory(500,$e->getMessage());
					}
				}
				else
				{				
					Alert::set(Alert::ALERT, __("Warning, Advertisement is already marked as 'deactivated'"));
					HTTP::redirect(Route::url('oc-panel',array('controller'=>'myads','action'=>'index')));
				} 
			}
			else
			{
				//throw 404
				throw HTTP_Exception::factory(404,__('Page not found'));
			}
		}
		
		Alert::set(Alert::SUCCESS, __('Advertisement is deactivated'));
		HTTP::redirect(Route::url('oc-panel',array('controller'=>'myads','action'=>'index')));
	}

	/**
	 * Mark advertisement as active : STATUS = 1
	 */
	
	public function action_activate()
	{
        $user = Auth::instance()->get_user();
		$id = $this->request->param('id');
		
		if (isset($id))
		{
			$active_ad = new Model_Ad($id);

			if ($active_ad->loaded())
			{
                $activate = FALSE;

                //admin whatever he wants
                if ($user->id_role == Model_Role::ROLE_ADMIN )
                {
                    $activate = TRUE;
                }
                //chekc user owner and if not moderation
                elseif ($user->id_user == $active_ad->id_user AND !in_array(core::config('general.moderation'), Model_Ad::$moderation_status) )
                {
                    $activate = TRUE;
                }
                else
                {
                    Alert::set(Alert::ALERT, __("This is not your advertisement."));
                }

                //its not published
                if ($active_ad->status == Model_Ad::STATUS_PUBLISHED)
                {
                    $activate = FALSE;
                    Alert::set(Alert::ALERT, __("Advertisement is already marked as 'active'"));
                }


                //activate the ad
                if ($activate === TRUE)
                {
                    $active_ad->published  = Date::unix2mysql(time());
                    $active_ad->status     = Model_Ad::STATUS_PUBLISHED;
                    
                    try
                    {
                        $active_ad->save();
                    }
                    catch (Exception $e)
                    {
                        throw HTTP_Exception::factory(500,$e->getMessage());
                    }
                }
                //we dont activate something happened
                else
                {
                    HTTP::redirect(Route::url('oc-panel',array('controller'=>'myads','action'=>'index')));
                }

			}
			else
			{
				//throw 404
				throw HTTP_Exception::factory(404,__('Page not found'));
			}
		}
		

		// send confirmation email
		$cat = new Model_Category($active_ad->id_category);
		$usr = new Model_User($active_ad->id_user);
		if($usr->loaded())
		{
            $edit_url   = Route::url('oc-panel', array('controller'=>'myads','action'=>'update','id'=>$active_ad->id_ad));
            $delete_url = Route::url('oc-panel', array('controller'=>'ad','action'=>'delete','id'=>$active_ad->id_ad));

			//we get the QL, and force the regen of token for security
			$url_ql = $usr->ql('ad',array( 'category' => $cat->seoname, 
		 	                                'seotitle'=> $active_ad->seotitle),TRUE);

			$ret = $usr->email('ads-activated',array('[USER.OWNER]'=>$usr->name,
													 '[URL.QL]'=>$url_ql,
													 '[AD.NAME]'=>$active_ad->title,
													 '[URL.EDITAD]'=>$edit_url,
                    								 '[URL.DELETEAD]'=>$delete_url));	
		}	

		Alert::set(Alert::SUCCESS, __('Advertisement is active and published'));
		HTTP::redirect(Route::url('oc-panel',array('controller'=>'myads','action'=>'index')));
	}

	/**
	 * Edit advertisement: Update
	 *
	 * All post fields are validated
	 */
	public function action_update()
	{
		//template header
		$this->template->title           	= __('Edit advertisement');
		$this->template->meta_description	= __('Edit advertisement');
		
		Controller::$full_width = TRUE;
		
		//local files
        if (Theme::get('cdn_files') == FALSE)
        {
            $this->template->styles = array('css/jquery.sceditor.default.min.css' => 'screen');
            
            $this->template->scripts['footer'] = array( 'js/jquery.sceditor.min.js',
                                                        'js/jquery.sceditor.bbcode.min.js',
                                                        'js/jquery.chained.min.js',
                                                        '//maps.google.com/maps/api/js?sensor=false&libraries=geometry&v=3.7',
                                                        '//cdn.jsdelivr.net/gmaps/0.4.15/gmaps.min.js',
                                                        'js/oc-panel/edit_ad.js');
        }
        else
        {
            $this->template->styles = array('css/jquery.sceditor.default.min.css' => 'screen');
            
            $this->template->scripts['footer'] = array( 'js/jquery.sceditor.min.js',
                                                        'js/jquery.sceditor.bbcode.min.js',
                                                        'js/jquery.chained.min.js',
                                                        '//maps.google.com/maps/api/js?sensor=false&libraries=geometry&v=3.7',
                                                        '//cdn.jsdelivr.net/gmaps/0.4.15/gmaps.min.js',
                                                        'js/oc-panel/edit_ad.js');
        }



		Breadcrumbs::add(Breadcrumb::factory()->set_title(__('Home'))->set_url(Route::url('default')));
		 	

		$form = new Model_Ad($this->request->param('id'));
		
	
		if(Auth::instance()->get_user()->id_user == $form->id_user 
            OR Auth::instance()->get_user()->id_role == Model_Role::ROLE_ADMIN
            OR Auth::instance()->get_user()->id_role == Model_Role::ROLE_MODERATOR)
		{
            $original_category = $form->category;

			$extra_payment = core::config('payment');

            $cat = new Model_Category();
            $loc = new Model_Location();
			
            //find all, for populating form select fields 
            $categories         = Model_Category::get_as_array();  
            $order_categories   = Model_Category::get_multidimensional();
            $parent_category    = Model_Category::get_by_deep();

            //get locations
            $locations         = Model_Location::get_as_array();  
            $order_locations   = Model_Location::get_multidimensional();
            $loc_parent_deep   = Model_Location::get_by_deep();

			
		
			if ($this->request->post())
			{
				
				// deleting single image by path 
				if(is_numeric($deleted_image = core::post('img_delete')))
				{
					$form->delete_image($deleted_image);
                    //TODO! usage of the api?
					die();
				}// end of img delete


                $data = $this->request->post();

                //to make it backward compatible with older themes: UGLY!!
                if (isset($data['category']) AND is_numeric($data['category']))
                {
                    $data['id_category'] = $data['category'];
                    unset($data['category']);
                }

                if (isset($data['location']) AND is_numeric($data['location']))
                {
                    $data['id_location'] = $data['location'];
                    unset($data['location']);
                }

                $return = $form->save_ad($data);
        
                //there was an error on the validation
                if (isset($return['validation_errors']) AND is_array($return['validation_errors']))
                {
                    foreach ($return['validation_errors'] as $f => $err) 
                        Alert::set(Alert::ALERT, $err);
                }
                //another error
                elseif (isset($return['error']))
                {
                    Alert::set($return['error_type'], $return['error']);
                }
                //success!!!
                elseif (isset($return['message']))
                {
                    // IMAGE UPLOAD 
                    // in case something wrong happens user is redirected to edit advert. 
                    $counter = 0;

                    for ($i=0; $i < core::config("advertisement.num_images"); $i++) 
                    { 
                        $filename = NULL;
                        $counter++;

                        if (isset($_FILES['image'.$i]))
                        {
                            $filename = $form->save_image($_FILES['image'.$i]);
                        }
                        
                        if ($filename)
                        {
                            $form->has_images++;
                            $form->last_modified = Date::unix2mysql();
                            try 
                            {
                                $form->save();
                            } 
                            catch (Exception $e) 
                            {
                                throw HTTP_Exception::factory(500,$e->getMessage());
                            }
                        }
                        //TODO
                        //if($filename == FALSE)
                            //$this->redirect(Route::url('oc-panel', array('controller'=>'myads','action'=>'update','id'=>$form->id_ad)));
                    }

                    Alert::set(Alert::SUCCESS, $return['message']);

                    //redirect user to pay
                    if (isset($return['checkout_url']) AND !empty($return['checkout_url']))
                        $this->redirect($return['checkout_url']);
                }


        		$this->redirect(Route::url('oc-panel', array('controller'	=>'myads', 'action' =>'update', 'id' =>$form->id_ad)));
	        	
			}

            //get all orders
            $orders = new Model_Order();
            $orders = $orders->where('id_user', '=', $form->id_user)
                            ->where('status','=',Model_Order::STATUS_CREATED)
                            ->where('id_ad','=',$form->id_ad)->find_all();

            Breadcrumbs::add(Breadcrumb::factory()->set_title("Update"));
            $this->template->content = View::factory('oc-panel/profile/edit_ad', array('ad'                 =>$form, 
                                                                                       'locations'          =>$locations,
                                                                                       'order_locations'    =>$order_locations, 
                                                                                       'categories'         =>$categories,
                                                                                       'order_categories'   =>$order_categories,
                                                                                       'order_parent_deep'  =>$parent_category,
                                                                                       'loc_parent_deep'    =>$loc_parent_deep,
                                                                                       'extra_payment'      =>$extra_payment,
                                                                                       'orders'             =>$orders,
                                                                                       'fields'             => Model_Field::get_all()));

		}
		else
		{
			Alert::set(Alert::ERROR, __('You dont have permission to access this link'));
			$this->redirect(Route::url('default'));
		}
	}

    /**
     * confirms the post of and advertisement
     * @return void 
     */
    public function action_confirm()
    {
        $advert = new Model_Ad($this->request->param('id'));

        if($advert->loaded())
        {

            if(Auth::instance()->get_user()->id_user !== $advert->id_user)
            {
                Alert::set(Alert::ALERT, __("This is not your advertisement."));
                HTTP::redirect(Route::url('oc-panel',array('controller'=>'myads','action'=>'index')));
            }

            if(core::config('general.moderation') == Model_Ad::EMAIL_CONFIRMATION)
            {
                $advert->status = Model_Ad::STATUS_PUBLISHED; // status active
                $advert->published = Date::unix2mysql();

                try 
                {
                    $advert->save();

                    //subscription is on
                    $data = array(  'title'         => $advert->title,
                                    'cat'           => $advert->category,
                                    'loc'           => $advert->location,  
                                 );

                    Model_Subscribe::find_subscribers($data, floatval(str_replace(',', '.', $advert->price)), $advert->seotitle); // if subscription is on
                    Alert::set(Alert::INFO, __('Your advertisement is successfully activated! Thank you!'));
                        
                } 
                catch (Exception $e) 
                {
                    throw HTTP_Exception::factory(500,$e->getMessage());
                }
            }
            elseif(core::config('general.moderation') == Model_Ad::EMAIL_MODERATION)
            {
                $advert->status = Model_Ad::STATUS_NOPUBLISHED;

                try 
                {
                    $advert->save();
                    Alert::set(Alert::INFO, __('Advertisement is received, but first administrator needs to validate. Thank you for being patient!'));
                } 
                catch (Exception $e) 
                {
                    throw HTTP_Exception::factory(500,$e->getMessage());
                }
            }

            $this->redirect(Route::url('ad', array('category'=>$advert->category->seoname, 'seotitle'=>$advert->seotitle)));
        }

    }

	public function action_stats()
   	{
        Breadcrumbs::add(Breadcrumb::factory()->set_title(__('Stats')));
        
        Controller::$full_width = TRUE;

        $this->template->scripts['footer'] = array('js/oc-panel/stats/dashboard.js');
        
        $this->template->title = __('Stats');
        $this->template->bind('content', $content);        
        $content = View::factory('oc-panel/profile/stats');


        $user    = Auth::instance()->get_user();
        $list_ad = array();
        $advert  = new Model_Ad();

        //single stats for 1 ad
        if (is_numeric($id_ad = $this->request->param('id')))
        {
            $advert = new Model_Ad($id_ad);

            if($advert->loaded())
            {
                if($user->id_user !== $advert->id_user)
                {
                    Alert::set(Alert::ALERT, __("This is not your advertisement."));
                    HTTP::redirect(Route::url('oc-panel',array('controller'=>'myads','action'=>'index')));
                }

                Breadcrumbs::add(Breadcrumb::factory()->set_title($advert->title));

                // make a list of 1 ad (array), and than pass this array to query (IN).. To get correct visits
                $list_ad[] = $id_ad;
            }
        }

        //we didnt filter by ad, so lets get them all!
        if (empty($list_ad))
        {
            $ads = new Model_Ad();
            $collection_of_user_ads = $ads->where('id_user', '=', $user->id_user)->find_all();

            $list_ad = array();
            foreach ($collection_of_user_ads as $key) {
                // make a list of ads (array), and than pass this array to query (IN).. To get correct visits
                $list_ad[] = $key->id_ad;
            }
        }

        // if user doesn't have any ads
        if(empty($list_ad))
            $list_ad = array(NULL);

        $content->advert = $advert;
        

        //Getting the dates and range
        $from_date = Core::post('from_date',strtotime('-1 month'));
        $to_date   = Core::post('to_date',time());

        //we assure is a proper time stamp if not we transform it
        if (is_string($from_date) === TRUE) 
            $from_date = strtotime($from_date);
        if (is_string($to_date) === TRUE) 
            $to_date   = strtotime($to_date);

        //mysql formated dates
        $my_from_date = Date::unix2mysql($from_date);
        $my_to_date   = Date::unix2mysql($to_date);

        //dates range we are filtering
        $dates     = Date::range($from_date, $to_date,'+1 day','Y-m-d',array('date'=>0,'count'=> 0),'date');
        
        //dates displayed in the form
        $content->from_date = date('Y-m-d',$from_date);
        $content->to_date   = date('Y-m-d',$to_date) ;

        
        /////////////////////CONTACT STATS////////////////////////////////

        //visits created last XX days
        $query = DB::select(DB::expr('DATE(created) date'))
                        ->select(DB::expr('COUNT(contacted) count'))
                        ->from('visits')
                        ->where('contacted', '=', 1)
                        ->where('id_ad', 'in', $list_ad)
                        ->where('created','between',array($my_from_date,$my_to_date))
                        ->group_by(DB::expr('DATE( created )'))
                        ->order_by('date','asc')
                        ->execute();

        $contacts_dates = $query->as_array('date');

        //Today 
        $query = DB::select(DB::expr('COUNT(contacted) count'))
                        ->from('visits')
                        ->where('contacted', '=', 1)
                        ->where('id_ad', 'in', $list_ad)
                        ->where(DB::expr('DATE( created )'),'=',DB::expr('CURDATE()'))
                        ->group_by(DB::expr('DATE( created )'))
                        ->order_by('created','asc')
                        ->execute();

        $contacts = $query->as_array();
        $content->contacts_today     = (isset($contacts[0]['count']))?$contacts[0]['count']:0;

        //Yesterday
        $query = DB::select(DB::expr('COUNT(contacted) count'))
                        ->from('visits')
                        ->where('contacted', '=', 1)
                        ->where('id_ad', 'in', $list_ad)
                        ->where(DB::expr('DATE( created )'),'=',date('Y-m-d',strtotime('-1 day')))
                        ->group_by(DB::expr('DATE( created )'))
                        ->order_by('created','asc')
                        ->execute();
        
        $contacts = $query->as_array();
        $content->contacts_yesterday = (isset($contacts[0]['count']))?$contacts[0]['count']:0; //

        //Last 30 days contacts
        $query = DB::select(DB::expr('COUNT(contacted) count'))
                        ->from('visits')
                        ->where('contacted', '=', 1)
                        ->where('id_ad', 'in', $list_ad)
                        ->where('created','between',array(date('Y-m-d',strtotime('-30 day')),date::unix2mysql()))
                        ->execute();

        $contacts = $query->as_array();
        $content->contacts_month = (isset($contacts[0]['count']))?$contacts[0]['count']:0;

        //total contacts
        $query = DB::select(DB::expr('COUNT(contacted) count'))
        				->where('contacted', '=', 1)
                        ->where('id_ad', 'in', $list_ad)
                        ->from('visits')
                        ->execute();

        $contacts = $query->as_array();
        $content->contacts_total = (isset($contacts[0]['count']))?$contacts[0]['count']:0;

        /////////////////////VISITS STATS////////////////////////////////

        //visits created last XX days
        $query = DB::select(DB::expr('DATE(created) date'))
                        ->select(DB::expr('COUNT(id_visit) count'))
                        ->from('visits')
                        ->where('id_ad', 'in', $list_ad)
                        ->where('created','between',array($my_from_date,$my_to_date))
                        ->group_by(DB::expr('DATE( created )'))
                        ->order_by('date','asc')
                        ->execute();

        $visits = $query->as_array('date');
 
        $stats_daily = array();
        foreach ($dates as $date) 
        {
            $count_contants = (isset($contacts_dates[$date['date']]['count']))?$contacts_dates[$date['date']]['count']:0;
            $count_visits = (isset($visits[$date['date']]['count']))?$visits[$date['date']]['count']:0;
            
            $stats_daily[] = array('date'=>$date['date'],'views'=> $count_visits, 'contacts'=>$count_contants);
        } 

        $content->stats_daily = $stats_daily;

        //Today 
        $query = DB::select(DB::expr('COUNT(id_visit) count'))
                        ->from('visits')
                        
                        ->where('id_ad', 'in', $list_ad)
                        ->where(DB::expr('DATE( created )'),'=',DB::expr('CURDATE()'))
                        ->group_by(DB::expr('DATE( created )'))
                        ->order_by('created','asc')
                        ->execute();

        $visits = $query->as_array();
        $content->visits_today     = (isset($visits[0]['count']))?$visits[0]['count']:0;

        //Yesterday
        $query = DB::select(DB::expr('COUNT(id_visit) count'))
                        ->from('visits')
                        
                        ->where('id_ad', 'in', $list_ad)
                        ->where(DB::expr('DATE( created )'),'=',date('Y-m-d',strtotime('-1 day')))
                        ->group_by(DB::expr('DATE( created )'))
                        ->order_by('created','asc')
                        ->execute();
        
        $visits = $query->as_array();
        $content->visits_yesterday = (isset($visits[0]['count']))?$visits[0]['count']:0;


        //Last 30 days visits
        $query = DB::select(DB::expr('COUNT(id_visit) count'))
                        ->from('visits')
                        ->where('id_ad', 'in', $list_ad)
                        ->where('created','between',array(date('Y-m-d',strtotime('-30 day')),date::unix2mysql()))
                        ->execute();

        $visits = $query->as_array();
        $content->visits_month = (isset($visits[0]['count']))?$visits[0]['count']:0;

        //total visits
        $query = DB::select(DB::expr('COUNT(id_visit) count'))
                        ->where('id_ad', 'in', $list_ad)
                        ->from('visits')
                        ->execute();

        $visits = $query->as_array();
        $content->visits_total = (isset($visits[0]['count']))?$visits[0]['count']:0;
        
    }

}
