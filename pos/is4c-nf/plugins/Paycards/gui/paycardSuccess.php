<?php
/*******************************************************************************

    Copyright 2001, 2004 Wedge Community Co-op

    This file is part of IT CORE.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

include_once(dirname(__FILE__).'/../../../lib/AutoLoader.php');

class paycardSuccess extends BasicPage {

	function preprocess(){
		global $CORE_LOCAL;

		// check for input
		if(isset($_REQUEST["reginput"])) {
			$input = strtoupper(trim($_POST["reginput"]));

            // capture file if present; otherwise re-request 
            // signature via terminal
            if (isset($_REQUEST['doCapture']) && $_REQUEST['doCapture'] == 1 && ($input == '' || $input == 'CL')) {
                if (isset($_REQUEST['bmpfile']) && !empty($_REQUEST['bmpfile']) && file_exists($_REQUEST['bmpfile'])) {
                    $bmp = file_get_contents($_REQUEST['bmpfile']);
                    $format = 'BMP';
                    $img_content = $bmp;

                    $dbc = Database::tDataConnect();
                    $capQ = 'INSERT INTO CapturedSignature
                                (tdate, emp_no, register_no, trans_no,
                                 trans_id, filetype, filecontents)
                             VALUES
                                (?, ?, ?, ?,
                                 ?, ?, ?)';
                    $capP = $dbc->prepare_statement($capQ);
                    $args = array(
                        date('Y-m-d H:i:s'),
                        $CORE_LOCAL->get('CashierNo'),
                        $CORE_LOCAL->get('laneno'),
                        $CORE_LOCAL->get('transno'),
                        $CORE_LOCAL->get('paycard_id'),
                        $format,
                        $img_content,
                    );
                    $capR = $dbc->exec_statement($capP, $args);

                    unlink($_REQUEST['bmpfile']);
                    // continue to below. finishing transaction is the same
                    // as with paper signature slip

                } else {
                    UdpComm::udpSend('termSig');

                    return true;
                }
            }

			$mode = $CORE_LOCAL->get("paycard_mode");
			$type = $CORE_LOCAL->get("paycard_type");
			$tender_id = $CORE_LOCAL->get("paycard_id");
			if( $input == "" || $input == "CL") { // [enter] or [clear] exits this screen
				// remember the mode, type and transid before we reset them
				$CORE_LOCAL->set("boxMsg","");

				PaycardLib::paycard_reset();
				UdpComm::udpSend('termReset');
                $CORE_LOCAL->set('ccTermState','swipe');
				$CORE_LOCAL->set("CacheCardType","");
				$CORE_LOCAL->set("strRemembered","TO");
				$CORE_LOCAL->set("msgrepeat",1);

				$this->change_page($this->page_url."gui-modules/pos2.php");
				return False;
			} else if ($mode == PaycardLib::PAYCARD_MODE_AUTH && $input == "VD" 
				&& ($CORE_LOCAL->get('CacheCardType') == 'CREDIT' || $CORE_LOCAL->get('CacheCardType') == '')){
				$plugin_info = new Paycards();
				$this->change_page($plugin_info->plugin_url()."/gui/paycardboxMsgVoid.php");
				return False;
			}
		}
		/* shouldn't happen unless session glitches
		   but getting here implies the transaction
		   succeeded */
		$var = $CORE_LOCAL->get("boxMsg");
		if (empty($var)){
			$CORE_LOCAL->set("boxMsg",
				"<b>Approved</b><font size=-1>
				<p>&nbsp;
				<p>[enter] to continue
				<br>[void] to cancel and void
				</font>");
		}
		return True;
	}

	function head_content(){
		?>
		<script type="text/javascript">
		function submitWrapper(){
			var str = $('#reginput').val();
			if (str.toUpperCase() == 'RP'){
				$.ajax({url: '<?php echo $this->page_url; ?>ajax-callbacks/ajax-end.php',
					cache: false,
					type: 'post',
					data: 'receiptType='+$('#rp_type').val(),
					success: function(data){}
				});
				$('#reginput').val('');
				return false;
			}
			return true;
		}
        function parseWrapper(str) {
            if (str.substring(0, 7) == 'TERMBMP') {
                var fn = '<?php echo $this->bmp_path; ?>' + str.substring(7);
                $('<input>').attr({
                    type: 'hidden',
                    name: 'bmpfile',
                    value: fn
                }).appendTo('#formlocal');

                var img = $('<img>').attr({
                    src: fn,
                    width: 250 
                });
                $('#imgArea').append(img);
                $('.boxMsgAlert').html('Approve Signature');
                $('#sigInstructions').html('[enter] to approve, [void] to cancel and void');
            } 
        }
        function addToForm(n, v) {
            $('<input>').attr({
                name: n,
                value: v,
                type: 'hidden'
            }).appendTo('#formlocal');
        }
		</script>
        <style type="text/css">
        #imgArea img { border: solid 1px; black; margin:5px; }
        </style>
		<?php
	}

	function body_content(){
		global $CORE_LOCAL;
		$this->input_header("onsubmit=\"return submitWrapper();\" action=\"".$_SERVER['PHP_SELF']."\"");
		?>
		<div class="baseHeight">
		<?php
		// Signature Capture support
        // If:
        //   a) enabled
        //   b) a Credit transaction
        //   c) Over limit threshold OR a return
        $isCredit = ($CORE_LOCAL->get('CacheCardType') == 'CREDIT' || $CORE_LOCAL->get('CacheCardType') == '') ? true : false;
        $needSig = ($CORE_LOCAL->get('paycard_amount') > $CORE_LOCAL->get('CCSigLimit') || $CORE_LOCAL->get('paycard_amount') < 0) ? true : false;
		if ($CORE_LOCAL->get("SigCapture") == 1 && $isCredit && $needSig) {
            echo "<div id=\"boxMsg\" class=\"centeredDisplay\">";

            echo "<div class=\"boxMsgAlert coloredArea\">";
            echo "Waiting for signature";
            echo "</div>";

            echo "<div class=\"\">";

            echo "<div id=\"imgArea\"></div>";
            echo '<div class="textArea">';
            echo '$' . sprintf('%.2f', $CORE_LOCAL->get('paycard_amount')) . ' as CREDIT';
            echo '<br />';
            echo '<span id="sigInstructions" style="font-size:90%;">';
            echo '[enter] to get re-request signature, [void] to cancel and void';
            echo '</span>';
            echo "</div>";

            echo "</div>"; // empty class
            echo "</div>"; // #boxMsg

            UdpComm::udpSend('termSig');
            $this->add_onload_command("addToForm('doCapture', '1');\n");
		} else {
            echo DisplayLib::boxMsg($CORE_LOCAL->get("boxMsg"), "", true);
            UdpComm::udpSend('termApproved');
        }
		$CORE_LOCAL->set("msgrepeat",2);
		$CORE_LOCAL->set("CachePanEncBlock","");
		$CORE_LOCAL->set("CachePinEncBlock","");
		?>
		</div>
		<?php
		echo "<div id=\"footer\">";
		echo DisplayLib::printfooter();
		echo "</div>";

		$rp_type = '';
		if( $CORE_LOCAL->get("paycard_type") == PaycardLib::PAYCARD_TYPE_GIFT) {
			if( $CORE_LOCAL->get("paycard_mode") == PaycardLib::PAYCARD_MODE_BALANCE) {
				$rp_type = "gcBalSlip";
			} 
			else {
				$rp_type ="gcSlip";
			}
		} 
		else if( $CORE_LOCAL->get("paycard_type") == PaycardLib::PAYCARD_TYPE_CREDIT) {
			$rp_type = "ccSlip";
		}
		else if( $CORE_LOCAL->get("paycard_type") == PaycardLib::PAYCARD_TYPE_ENCRYPTED) {
			$rp_type = "ccSlip";
		}
		printf("<input type=\"hidden\" id=\"rp_type\" value=\"%s\" />",$rp_type);
	}
}

if (basename($_SERVER['PHP_SELF']) == basename(__FILE__))
	new paycardSuccess();

