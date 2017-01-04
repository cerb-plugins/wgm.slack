<?php
if(class_exists('Extension_PageMenuItem')):
class WgmSlack_SetupMenuItem extends Extension_PageMenuItem {
	const POINT = 'wgm.slack.setup.menu';
	
	function render() {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl->assign('extension', $this);
		$tpl->display('devblocks:wgm.slack::setup/menu_item.tpl');
	}
};
endif;

if(class_exists('Extension_PageSection')):
class WgmSlack_SetupSection extends Extension_PageSection {
	const ID = 'wgm.slack.setup.page';
	
	function render() {
		$tpl = DevblocksPlatform::getTemplateService();
		$visit = CerberusApplication::getVisit();
		
		$visit->set(ChConfigurationPage::ID, 'slack');
		
		$credentials = DevblocksPlatform::getPluginSetting('wgm.slack','credentials',false,true,true);
		$tpl->assign('credentials', $credentials);
		
		$tpl->display('devblocks:wgm.slack::setup/index.tpl');
	}
	
	function saveJsonAction() {
		try {
			@$consumer_key = DevblocksPlatform::importGPC($_REQUEST['consumer_key'],'string','');
			@$consumer_secret = DevblocksPlatform::importGPC($_REQUEST['consumer_secret'],'string','');
			
			if(empty($consumer_key) || empty($consumer_secret))
				throw new Exception("Both the 'Client ID' and 'Client Secret' are required.");
			
			$credentials = [
				'consumer_key' => $consumer_key,
				'consumer_secret' => $consumer_secret,
			];
			DevblocksPlatform::setPluginSetting('wgm.slack','credentials',$credentials,true,true);
			
			echo json_encode(array('status'=>true, 'message'=>'Saved!'));
			return;
			
		} catch (Exception $e) {
			echo json_encode(array('status'=>false, 'error'=>$e->getMessage()));
			return;
		}
	}
};
endif;

class ServiceProvider_Slack extends Extension_ServiceProvider implements IServiceProvider_OAuth, IServiceProvider_HttpRequestSigner {
	const ID = 'wgm.slack.service.provider';

	private function _getAppKeys() {
		if(false == ($credentials = DevblocksPlatform::getPluginSetting('wgm.slack','credentials',false,true,true)))
			return false;
		
		@$consumer_key = $credentials['consumer_key'];
		@$consumer_secret = $credentials['consumer_secret'];
		
		if(empty($consumer_key) || empty($consumer_secret))
			return false;
		
		return array(
			'key' => $consumer_key,
			'secret' => $consumer_secret,
		);
	}
	
	function renderPopup() {
		@$view_id = DevblocksPlatform::importGPC($_REQUEST['view_id'], 'string', '');
		
		$url_writer = DevblocksPlatform::getUrlService();
		
		// [TODO] Report about missing app keys
		if(false == ($app_keys = $this->_getAppKeys()))
			return false;
		
		$oauth = DevblocksPlatform::getOAuthService($app_keys['key'], $app_keys['secret']);
		
		// Persist the view_id in the session
		$_SESSION['oauth_view_id'] = $view_id;
		$_SESSION['oauth_state'] = CerberusApplication::generatePassword(24);
		
		// OAuth callback
		$redirect_url = $url_writer->write(sprintf('c=oauth&a=callback&ext=%s', ServiceProvider_Slack::ID), true);

		$url = sprintf("%s?response_type=code&client_id=%s&redirect_uri=%s&scope=%s&state=%s",
			'https://slack.com/oauth/authorize',
			rawurlencode($app_keys['key']),
			rawurlencode($redirect_url),
			rawurlencode('channels:read chat:write:bot chat:write:user im:read im:write users:read'), // identity.basic 
			rawurlencode($_SESSION['oauth_state'])
		);
		
		header('Location: ' . $url);
	}
	
	function oauthCallback() {
		@$view_id = $_SESSION['oauth_view_id'];
		@$oauth_state = $_SESSION['oauth_state'];
		
		@$code = DevblocksPlatform::importGPC($_REQUEST['code'], 'string', '');
		@$state = DevblocksPlatform::importGPC($_REQUEST['state'], 'string', '');
		@$error = DevblocksPlatform::importGPC($_REQUEST['error'], 'string', '');
		
		$active_worker = CerberusApplication::getActiveWorker();
		$url_writer = DevblocksPlatform::getUrlService();
		
		$redirect_url = $url_writer->write(sprintf('c=oauth&a=callback&ext=%s', ServiceProvider_Slack::ID), true);
		
		if(false == ($app_keys = $this->_getAppKeys()))
			return false;
		
		if(!empty($error))
			return false;
		
		$access_token_url = 'https://slack.com/api/oauth.access';
		
		$oauth = DevblocksPlatform::getOAuthService($app_keys['key'], $app_keys['secret']);
		$oauth->setTokens($code);
		
		$params = $oauth->getAccessToken($access_token_url, array(
			'code' => $code,
			'redirect_uri' => $redirect_url,
			'client_id' => $app_keys['key'],
			'client_secret' => $app_keys['secret'],
		));
		
		if(!is_array($params) || !isset($params['access_token']) || !isset($params['user_id'])) {
			return false;
		}
		
		$oauth->setTokens($params['access_token']);
		
		$label = 'Slack';
		
		// Load their profile
		
		$url = sprintf('https://slack.com/api/users.info?user=%s&token=%s',
			rawurlencode($params['user_id']),
			rawurlencode($params['access_token'])
		);
		$ch = DevblocksPlatform::curlInit($url);
		
		if(false == ($out = DevblocksPlatform::curlExec($ch)))
			return false;
		
		curl_close($ch);
		
		if(false == ($json = json_decode($out, true)))
			return false;
		
		// Die with error
		if(!is_array($json) || !isset($json['user']) && !is_array($json['user']) && !isset($json['user']['name']))
			return false;
		
		$label .= sprintf(": %s", $json['user']['name']);
		
		// Save the account
		
		$id = DAO_ConnectedAccount::create(array(
			DAO_ConnectedAccount::NAME => $label,
			DAO_ConnectedAccount::EXTENSION_ID => ServiceProvider_Slack::ID,
			DAO_ConnectedAccount::OWNER_CONTEXT => CerberusContexts::CONTEXT_WORKER,
			DAO_ConnectedAccount::OWNER_CONTEXT_ID => $active_worker->id,
		));
		
		DAO_ConnectedAccount::setAndEncryptParams($id, $params);
		
		if($view_id) {
			echo sprintf("<script>window.opener.genericAjaxGet('view%s', 'c=internal&a=viewRefresh&id=%s');</script>",
				rawurlencode($view_id),
				rawurlencode($view_id)
			);
			
			C4_AbstractView::setMarqueeContextCreated($view_id, CerberusContexts::CONTEXT_CONNECTED_ACCOUNT, $id);
		}
		
		echo "<script>window.close();</script>";
	}
	
	function authenticateHttpRequest(Model_ConnectedAccount $account, &$ch, &$verb, &$url, &$body, &$headers) {
		$credentials = $account->decryptParams();
		
		if(
			!isset($credentials['access_token'])
		)
			return false;
		
		if(false == ($url_parts = parse_url($url)))
			return false;
		
		if(false !== stripos($url,'?')) {
			$url .= '&token=' . rawurlencode($credentials['access_token']);
		} else {
			$url .= '?token=' . rawurlencode($credentials['access_token']);
		}
		
		curl_setopt($ch, CURLOPT_URL, $url);
		
		return true;
	}
}