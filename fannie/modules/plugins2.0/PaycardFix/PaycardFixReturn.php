<?php

include(__DIR__ . '/../../../config.php');
if (!class_exists('FannieAPI')) {
    include(__DIR__ . '/../../../classlib2.0/FannieAPI.php');
}
if (!class_exists('MSoapClient')) {
    include(__DIR__ . '/../RecurringEquity/MSoapClient.php');
}

class PaycardFixReturn extends FannieRESTfulPage
{

    protected $header = 'Refund Payment Card Transaction';
    protected $title = 'Refund Payment Card Transaction';
    public $discoverable = true;
    protected $must_authenticate = true;
    protected $auth_classes = array('admin');

    public function preprocess()
    {
        $this->addRoute('get<resultID>');
        return parent::preprocess();
    }

    private function paycardTransP($table)
    {
        return $this->connection->prepare("
            SELECT *
            FROM {$table}
            WHERE dateID=?
                AND refNum=?
                AND xResultCode=1
                AND xResultMessage LIKE '%approve%'
                AND xResultMessage NOT LIKE '%decline%'
                AND transType='Sale'
                AND xToken <> ''
                AND xToken IS NOT NULL
        ");
    }

    private function markUsed($table, $date, $invoice)
    {
        $prep = $this->connection->prepare("
            UPDATE {$table}
            SET xToken = CONCAT(xToken, '-used')
            WHERE dateID=?
                AND refNum=?
                AND xTransactionID <> ''
                AND xToken <> ''
                AND xToken IS NOT NULL
                AND xResultCode=1
                AND transType='Sale'");
        $res = $this->connection->execute($prep, array(date('Ymd', strtotime($date)), $invoice));
    }

    private function refnum($emp, $reg, $trans, $id)
    {
        $ref = "";
        $ref .= date("md");
        $ref .= str_pad($emp, 4, "0", STR_PAD_LEFT);
        $ref .= str_pad($reg,    2, "0", STR_PAD_LEFT);
        $ref .= str_pad($trans,   3, "0", STR_PAD_LEFT);
        $ref .= str_pad($id,   3, "0", STR_PAD_LEFT);
        return $ref;
    }

    protected function get_resultID_view()
    {
        $table = $this->config->get('TRANS_DB') . $this->connection->sep() . 'PaycardTransactions';
        $prep = $this->connection->prepare("SELECT * FROM {$table} WHERE storeRowID=? and dateID=?");
        $row = $this->connection->getRow($prep, array($this->resultID, date('Ymd')));
        $ret = '<table class="table table-bordered table-striped">';
        foreach ($row as $key => $val) {
            if (!is_numeric($key)) {
                $ret .= "<tr><th>{$key}</th><td>{$val}</td></tr>";
            }
        }
        $ret .= '</table>';

        return $ret;
    }

    /**
     * Process the actual refund. Long so comments are
     * internal
     */
    protected function post_handler()
    {
        try {
            $pDate = $this->form->date;
            $invoice = $this->form->invoice;
            $amount = $this->form->amount;
            $transNum = $this->form->transNum;
            $tenderID = $this->form->tenderID;
        } catch (Exception $ex) {
            echo 'Bad submission';
            return false;
        }

        $table = $this->config->get('TRANS_DB') . $this->connection->sep() . 'PaycardTransactions';
        $findP = $this->paycardTransP($table);
        $ptrans = $this->connection->getRow($findP, array(date('Ymd', strtotime($pDate)), $invoice));
        if ($ptrans == false) {
            echo 'Bad submission' . $pDate;
            return false;
        }

        // figure out POS transaction numbering depending whether this
        // is a new POS transaction or not
        $EMP = 1001;
        $REG = 30;
        $TRANS = DTrans::getTransNo($this->connection, $EMP, $REG);
        $TRANS_ID = 2;
        if ($transNum) {
            list($EMP, $REG, $TRANS) = explode('-', $transNum);
            $TRANS_ID = $tenderID;
        }
        $newInvoice = $this->refnum($EMP, $REG, $TRANS, $TRANS_ID);

        // prepare PaycardTransactions INSERT
        $ptransP = $this->connection->prepare("INSERT INTO {$table} (dateID, empNo, registerNo, transNo, transID,
            previousPaycardTransactionID, processor, refNum, live, cardType, transType, amount, PAN, issuer,
            name, manual, requestDatetime, responseDatetime, seconds, commErr, httpCode, validResponse,
            xResultCode, xApprovalNumber, xResponseCode, xResultMessage, xTransactionID, xBalance, xToken,
            xProcessorRef, xAcquirerRef) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $pcRow = array(
            date('Ymd'),
            $EMP,
            $REG,
            $TRANS,
            $TRANS_ID,
            $ptrans['paycardTransactionID'],
            'MercuryE2E',
            $newInvoice,
            1,
            'CREDIT',
            'Return',
            $amount,
            $ptrans['PAN'],
            $ptrans['issuer'],
            $ptrans['name'],
            $ptrans['manual'],
            date('Y-m-d H:i:s'),
        );

        $credentials = json_decode(file_get_contents(__DIR__ . '/../RecurringEquity/credentials.json'), true);
        $storeID = $ptrans['registerNo'] < 10 ? 1 : 2;
        if (!is_array($credentials) || !isset($credentials[$storeID])) {
            echo 'Cannot find acct info';
            return false;
        }

        /**
         * Run transaction using soap client. Regardless of whether it succeeds, fails,
         * or throws an exception finish the necessary arguments from the new
         * PaycardTransactions record
         */
        $startTime = microtime(true);
        $success = false;
        $fp =fopen(__DIR__ . '/resp.log', 'a');
        try {
            $soap = new MSoapClient($credentials[$storeID][0], $credentials[$storeID][1]);
            $xml = $soap->oneReturnByRecordNo($amount, $ptrans['xToken'], $ptrans['xTransactionID'], $newInvoice);
            fwrite($fp, print_r($xml, true) . "\n");
            $elapsed = microtime(true) - $startTime;
            $pcRow[] = date('Y-m-d H:i:s');
            $pcRow[] = $elapsed;
            $pcRow[] = 0;
            $pcRow[] = 200;
            $pcRow[] = 1; // valid response
            $status = strtolower($xml->CmdResponse->CmdStatus);
            if ($status == 'approved') { // finish record as approved
                $success = true;
                $pcRow[] = 1;
                $pcRow[] = $xml->TranResponse->AuthCode;
                $pcRow[] = $xml->CmdResponse->DSIXReturnCode;
                $pcRow[] = $xml->CmdResponse->TextResponse;
                $pcRow[] = $xml->TranResponse->RefNo;
                $pcRow[] = 0; // xBalance
                $pcRow[] = $xml->TranResponse->RecordNo;
                $pcRow[] = $xml->TranResponse->ProcessData;
                $pcRow[] = $xml->TranResponse->AcqRefData;
            } else { // finish record as declined or errored
                $pcRow[] = $status == 'declined' ? 2 : 3;
                $pcRow[] = ''; // xApprovalNumber
                $pcRow[] = $xml->CmdResponse->DSIXReturnCode;
                $pcRow[] = $status == 'declined' ? 'DECLINED' : $xml->CmdResponse->TextResponse;
                $pcRow[] = ''; // xTransactionID
                $pcRow[] = 0; // xBalance
                $pcRow[] = ''; // xToken
                $pcRow[] = ''; // xProcessorRef
                $pcRow[] = ''; // xAcquirerRef
            }
        } catch (SoapFault $ex) { // also finish record as an error
            fwrite($fp, print_r($ex, true) . "\n");
            $elapsed = microtime(true) - $startTime;
            $pcRow[] = date('Y-m-d H:i:s');
            $pcRow[] = $elapsed;
            $pcRow[] = 1;
            $pcRow[] = $ex->faultcode;
            $pcRow[] = 1; // valid response
            $pcRow[] = 3; // xResultCode
            $pcRow[] = ''; // xApprovalNumber
            $pcRow[] = 0; // xResponseCode
            $pcRow[] = $ex->faultstring;
            $pcRow[] = ''; // xTransactionID
            $pcRow[] = 0; // xBalance
            $pcRow[] = ''; // xToken
            $pcRow[] = ''; // xProcessorRef
            $pcRow[] = ''; // xAcquirerRef
        }
        $this->connection->execute($ptransP, $pcRow);
        $pcID = $this->connection->insertID();

        /**
         * If the refund was successful, either flag the existing POS transaction with
         * the appropriate ID or create a new, two-record transaction
         */
        $dtransactions = $this->config->get('TRANS_DB') . $this->connection->sep() . 'dtransactions';
        if ($success && $transNum) {
            $upP = $this->connection->prepare("
                UPDATE dtransactions
                SET charflag='PT',
                    numflag=?
                WHERE datetime >= ?
                    AND emp_no=?
                    AND register_no=?
                    AND trans_no=?
                    AND trans_id=?");
            $upR = $this->connection->execute($upP, array($pcID, date('Y-m-d'), $EMP, $REG, $TRANS, $TRANS_ID));
            $this->markUsed($table, $pDate, $invoice);
        } elseif ($success) {
            $dtrans = DTrans::defaults();
            $dtrans['emp_no'] = $EMP;
            $dtrans['register_no'] = $REG;
            $dtrans['trans_no'] = $TRANS;
            $dtrans['trans_type'] = 'D';
            $dtrans['department'] = 703;
            $dtrans['description'] = 'MISCRECEIPT';
            $dtrans['upc'] = $amount . 'DP703';
            $dtrans['quantity'] = 1;
            $dtrans['ItemQtty'] = 1;
            $dtrans['trans_id'] = 1;
            $dtrans['total'] = -1*$amount;
            $dtrans['unitPrice'] = -1*$amount;
            $dtrans['regPrice'] = -1*$amount;
            $dtrans['card_no'] = 11;
            $prep = DTrans::parameterize($dtrans, 'datetime', $this->connection->now());
            $insP = $this->connection->prepare("INSERT INTO {$dtransactions} ({$prep['columnString']}) VALUES ({$prep['valueString']})");
            $insR = $this->connection->execute($insP, $prep['arguments']);

            $dtrans['trans_type'] = 'T';
            $dtrans['trans_subtype'] = 'CC';
            $dtrans['department'] = 0;
            $dtrans['description'] = 'Credit Card';
            $dtrans['upc'] = '';
            $dtrans['quantity'] = 0;
            $dtrans['ItemQtty'] = 0;
            $dtrans['trans_id'] = 2;
            $dtrans['total'] = $amount;
            $dtrans['unitPrice'] = 0;
            $dtrans['regPrice'] = 0;
            $dtrans['charflag'] = 'PT';
            $dtrans['numflag'] = $pcID;
            $prep = DTrans::parameterize($dtrans, 'datetime', $this->connection->now());
            $insR = $this->connection->execute($insP, $prep['arguments']);
            $this->markUsed($table, $pDate, $invoice);
        }

        return 'PaycardFixReturn.php?resultID=' . $pcID;
    }

    /**
     * Validate the submitted form info
     *
     * Make sure that:
     *  1. PaycardTransaction record exists
     *  2. PaycardTransaction record is unique
     *  3. Amount is valid, if provided
     *  4. Existing dtransactions trans_num is valid, if provided
     *
     * If validation succeeds, create a POST form to continue
     */
    protected function get_id_view()
    {
        try {
            $pDate = trim($this->form->date);
            $transNum = trim($this->form->correctTrans);
            $amount = trim($this->form->amount);
        } catch (Exception $ex) {
            return '<div class="alert alert-danger">Invalid data</div>'
                . $this->get_view();
        }

        $table = $this->config->get('TRANS_DB') . $this->connection->sep() . 'PaycardTransactions';
        $findP = $this->paycardTransP($table);
        $findR = $this->connection->execute($findP, array(date('Ymd', strtotime($pDate)), $this->id));
        if ($this->connection->numRows($findR) == 0) {
            return '<div class="alert alert-danger">Paycard transaction not found</div>'
                . $this->get_view();
        }
        if ($this->connection->numRows($findR) > 1) {
            return '<div class="alert alert-danger">Multiple matching transactions; cannot continue</div>'
                . $this->get_view();
        }
        $ptrans = $this->connection->fetchRow($findR);
        if ($amount != '' && $amount > $ptrans['amount']) {
            return sprintf('<div class="alert alert-danger">Invalid amount; maximum refund is $%.2f</div>', $ptrans['amount'])
                . $this->get_view();
        }

        $returnAmount = $amount != '' ? $amount : $ptrans['amount'];

        $tenderID = 0;
        if ($transNum) {
            $dlog = $this->config->get('TRANS_DB') . $this->connection->sep() . 'dlog';
            $correctP = $this->connection->prepare("
                SELECT *
                FROM {$dlog}
                WHERE tdate >= ?
                    AND trans_num=?
                    AND trans_type='T'
                    AND trans_subtype IN ('CC', 'AX')
                    AND total <> 0"
            );
            $cRow = $this->connection->getRow($correctP, array(date('Y-m-d', $transNum)));
            if ($cRow === false) {
                return '<div class="alert alert-danger">Correction transaction not found</div>'
                    . $this->get_view();
            }
            if ($returnAmount != (-1*$cRow['total'])) {
                return sprintf('<div class="alert alert-danger">Correction transaction amount (%.2f) does not
                    match charge refund amount (%.2f)</div>', -1*$cRow['total'], $returnAmount)
                    . $this->get_view();
            }
            $tenderID = $cRow['trans_id'];
        }

        $using = $transNum ? "transaction {$transNum}" : "a new transaction";
        $returnAmount = sprintf('%.2f', $returnAmount);

        return <<<HTML
<p>
Refunding \${$returnAmount} to card {$ptrans['PAN']} using {$using}.
</p>
<form method="post" action="PaycardFixReturn.php">
    <input type="hidden" name="date" value="{$pDate}" />
    <input type="hidden" name="invoice" value="{$this->id}" />
    <input type="hidden" name="amount" value="{$returnAmount}" />
    <input type="hidden" name="transNum" value="{$transNum}" />
    <input type="hidden" name="tenderID" value="{$tenderID}" />
    <div class="form-group">
        <button type="submit" class="btn btn-default btn-core">Process Refund</button>
    </div>
</form>
HTML;
    }

    /**
     * Create a form to start the refund process
     */
    protected function get_view()
    {
        return <<<HTML
<form method="get">
    <div class="form-group">
        <label>Card Transaction Date</label>
        <input type="text" class="form-control date-field" required name="date" />
    </div>
    <div class="form-group">
        <label>Card Transaction Invoice #</label>
        <input type="text" class="form-control" required name="id" />
    </div>
    <div class="form-group">
        <label>Correction Transaction #</label>
        <input type="text" class="form-control" name="correctTrans"
            placeholder="Optional; correction transaction to associate this charge with" />
    </div>
    <div class="form-group">
        <label>Return Amount</label>
        <input type="text" class="form-control" name="amount"
            placeholder="Optional; leave blank to refund the entire charge" />
    </div>
    <div class="form-group">
        <button type="submit" class="btn btn-default btn-core">Continue</button>
    </div>
</form>
HTML;
    }
}

FannieDispatch::conditionalExec();

