<?php

class TadoSmartACControl extends IPSModule
{
    /**
     * Log Message
     * @param string $Message
     */
    protected function Log($Message)
    {
        IPS_LogMessage(__CLASS__, $Message);
    }

    /**
     * Create
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString("AccessToken", "");
        $this->RegisterPropertyInteger("Zones", 0);
        $this->RegisterPropertyInteger("Poller", 1);
        $this->RegisterTimer("Update", 0, "Tado_Update($this->InstanceID);");
    }

    /**
     * ApplyChanges
     */
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->RegisterVariableBoolean("Link", "Online", "", 1);
        $this->RegisterVariableFloat("insideTemperature", "Temperature", "~Temperature", 2);
        $this->RegisterVariableFloat("humidity", "Humidity", "~Humidity.F", 3);
        $this->RegisterVariableBoolean("power", "Power", "~Switch", 4);
        $this->RegisterVariableString("mode", "Mode", "", 5);
        $this->RegisterVariableFloat("temperature", "Temperature SET", "~Temperature", 6);
        $this->RegisterVariableString("fanSpeed", "FanSpeed", "", 7);

        // $this->Login();
        $this->SetTimerInterval("Update", $this->ReadPropertyInteger("Poller") * 1000);
    }

    /**
     * GetConfigurationForm
     * @return string
     */
    public function GetConfigurationForm()
    {
        if ($this->GetBuffer("RefreshToken") != "") {
            $me = @$this->Api("me");
            if (isset($me->name)) {
                $Name = "Name: " . $me->name;
                $Homeid = $me->homes[0]->id;
                $Zones = $this->Api("homes/" . $Homeid . "/zones");
                $Zone = "";
                for ($i = 0; $i < count($Zones); $i++) {
                    $Zone .= '{ "label": "' . $Zones[$i]->name . ' (' . $Zones[$i]->id . ')", "value": ' . $Zones[$i]->id . ' },';
                }
                $Zone = substr($Zone, 0, -1);
                if ($this->ReadPropertyInteger("Zones") > 0) {
                    $serialNo = "Device: " . $Zones[$this->ReadPropertyInteger("Zones") - 1]->devices[0]->serialNo;
                    $this->SetSummary($Zones[$this->ReadPropertyInteger("Zones") - 1]->name);
                }
            }
        } else {
            $Name = "Please Login ...";
            //$Zone = '{ "label": "Please Login ...", "value": 0 }';
        }
        return '
            {
                 "elements":
                [
                    { "type": "Label", "label": "' . @$Name . '" },
                    { "type": "Label", "label": "' . @$serialNo . '" },
                    { "name": "Zones", "type": "Select", "caption": "Zones",
                        "options":
                        [
                             ' . @$Zone . '
                        ]
                     },
                    { "name": "Poller", "type": "IntervalBox", "caption": "Seconds" }
                ],
                "actions":
                [
                     { "type": "Button", "caption": "LOGIN", "onClick": "echo Tado_GetCodeURL(' . $this->InstanceID . ');" }
                ],
                "status":
                [
                    { "code": 102, "icon": "active", "caption": "" },
	                { "code": 104, "icon": "inactive", "caption": "" },
                    { "code": 200, "icon": "error", "caption": "It is an error state. Please check message log for more information." },
                    { "code": 201, "icon": "error", "caption": "Authentication failed" },
                    { "code": 202, "icon": "error", "caption": "Please Set Zones!" }
                ]
            }';
    }

    /**
     * Login
     */
    private function Login()
    {
        $device_code = $this->GetBuffer("device_code");
        $this->SetBuffer("AccessToken", "");
        $this->SetBuffer("RefreshToken", "");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://login.tado.com/oauth2/token');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, "client_id=1bb50063-6b0c-4d11-bd99-387f4a91cc46&device_code=" . $device_code . "&grant_type=urn:ietf:params:oauth:grant-type:device_code");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->ReadPropertyInteger("Poller"));
        $result = json_decode(curl_exec($ch));
        curl_close($ch);
        $this->SendDebug("Login", print_r($result, true), false);
        if (isset($result->access_token)) {
            $this->SetStatus(102);
            if ($this->ReadPropertyInteger("Zones") == 0) $this->SetStatus(202);
            $this->SetBuffer("AccessToken", $result->access_token);
            $this->SetBuffer("RefreshToken", $result->refresh_token);
            $this->SendDebug("RefreshToken", $result->refresh_token, false);
            $this->ReloadForm();
        } else {
            $this->SetStatus(201);
        }
    }

    /**
     * Api
     * @param string $Api
     * @return mixed
     */
    private function Api($Api)
    {
        $authorization = "Authorization: Bearer " . $this->GetBuffer("AccessToken");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://my.tado.com/api/v2/' . $Api);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $authorization));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->ReadPropertyInteger("Poller"));
        $result = json_decode(curl_exec($ch));
        curl_close($ch);
        if (isset($result->errors)) {
            $this->SetStatus(200);
            $this->SendDebug("Error", $result->errors[0]->title . $result->errors[0]->code, false);
            $this->Log($result->errors[0]->title . $result->errors[0]->code);
            exit;
        } else {
            $this->SetStatus(102);
            if ($this->ReadPropertyInteger("Zones") == 0) $this->SetStatus(202);
            return $result;
        }
    }

    /**
     * RefreshToken
     */
    private function RefreshToken()
    {
        if ((int)$this->GetBuffer("Timestamp") + 600 > time()) return;
        $refresh_token = $this->GetBuffer("RefreshToken");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://login.tado.com/oauth2/token');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, "client_id=1bb50063-6b0c-4d11-bd99-387f4a91cc46&refresh_token=" . $refresh_token . "&grant_type=refresh_token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->ReadPropertyInteger("Poller"));
        $result = json_decode(curl_exec($ch));
        curl_close($ch);
        $this->SendDebug("RefreshToken", print_r($result, true), false);
        if (isset($result->access_token)) {
            $this->SetBuffer("AccessToken", $result->access_token);
            $this->SetBuffer("RefreshToken", $result->refresh_token);
            $this->SetBuffer("Timestamp", time());
        } else {
            $this->Login();
        }
    }

    public function GetCodeURL()
    {
        // return "https://login.tado.com/oauth2/device?user_code=ZQ5QCN";
        $this->SetBuffer("AccessToken", "");
        $this->SetBuffer("RefreshToken", "");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://login.tado.com/oauth2/device_authorize');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, "client_id=1bb50063-6b0c-4d11-bd99-387f4a91cc46&scope=offline_access");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = json_decode(curl_exec($ch));
        curl_close($ch);
        $this->SendDebug("GetCodeURL", print_r($result, true), false);
        if (isset($result->verification_uri_complete)) {
            $this->SetBuffer("device_code", $result->device_code);
            $this->SendDebug("device_code", (string)$result->device_code, false);
            $this->SendDebug("verification_uri_complete", (string)$result->verification_uri_complete, false);
            return (string)$result->verification_uri_complete;
        } else {
            $this->SendDebug("GetCodeURL", print_r($result, true), false);
            return "";
        }
    }

    /**
     * Update
     */
    public function Update()
    {
        if ($this->GetBuffer("RefreshToken") == "") {
            $this->Login();
            return;
        }
        $this->RefreshToken();
        if ($this->ReadPropertyInteger("Zones") == 0) return;
        $Homeid = $this->Api("me")->homes[0]->id;
        $State = $this->Api("homes/" . $Homeid . "/zones/" . $this->ReadPropertyInteger("Zones") . "/state");
        if ($State->link->state == "ONLINE") {
            $this->SetValue($this->GetIDForIdent("Link"), true);
        } else {
            $this->SetValue($this->GetIDForIdent("Link"), false);
        }
        $this->SetValue($this->GetIDForIdent("insideTemperature"), (float) $State->sensorDataPoints->insideTemperature->celsius);
        $this->SetValue($this->GetIDForIdent("humidity"), (float) $State->sensorDataPoints->humidity->percentage);
        if ($State->setting->power == "ON") {
            $this->SetValue($this->GetIDForIdent("power"), true);
        } else {
            $this->SetValue($this->GetIDForIdent("power"), false);
        }
        $this->SetValue($this->GetIDForIdent("mode"), @$State->setting->mode);
        $this->SetValue($this->GetIDForIdent("temperature"), (float) @$State->setting->temperature->celsius);
        $this->SetValue($this->GetIDForIdent("fanSpeed"), @$State->setting->fanSpeed);
    }

    /**
     * Tado_ACPowerOff
     */
    public function ACPowerOff()
    {
        $this->RefreshToken();
        $Homeid = $this->Api("me")->homes[0]->id;
        $Zones = $this->ReadPropertyInteger("Zones");
        $authorization = "Authorization: Bearer " . $this->GetBuffer("AccessToken");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://my.tado.com/api/v2/homes/' . $Homeid . '/zones/' . $Zones . '/overlay');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $authorization));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{"setting":{"type":"AIR_CONDITIONING","power":"OFF"},"termination":{"type":"MANUAL"}}');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Tado_ACSetMode
     * @param string $Mode
     * @param string $Temperature
     * @param string $FanSpeed
     */
    public function ACSetMode(string $Mode, string $Temperature, string $FanSpeed)
    {
        $this->RefreshToken();
        $Homeid = $this->Api("me")->homes[0]->id;
        $Zones = $this->ReadPropertyInteger("Zones");
        $authorization = "Authorization: Bearer " . $this->GetBuffer("AccessToken");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://my.tado.com/api/v2/homes/' . $Homeid . '/zones/' . $Zones . '/overlay');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $authorization));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{"setting":{"type":"AIR_CONDITIONING","power":"ON","mode":"' . $Mode . '","temperature":{"celsius":' . $Temperature . '},"fanSpeed":"' . $FanSpeed . '"},"termination":{"type":"MANUAL"}}');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
    }

    /**
     * SetValue
     * @param integer $ID
     * @param type $Value
     */
    protected function SetValue($ID, $Value)
    {
        if (GetValue($ID) !== $Value) {
            SetValue($ID, $Value);
        }
    }
}
