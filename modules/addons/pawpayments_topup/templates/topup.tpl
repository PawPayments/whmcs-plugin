<div class="card">
    <div class="card-body">
        <h3 class="card-title">Crypto Deposit</h3>
        <p class="text-muted">Add funds to your account using cryptocurrency.</p>

        {if $success}
            <div class="alert alert-success">
                Deposit successful! Funds have been added to your account.
            </div>
            <a href="clientarea.php" class="btn btn-primary">Back to Dashboard</a>
        {elseif $cancelled}
            <div class="alert alert-warning">
                Deposit was cancelled.
            </div>
            <a href="index.php?m=pawpayments_topup" class="btn btn-primary">Try Again</a>
        {else}
            {if $error}
                <div class="alert alert-danger">{$error}</div>
            {/if}

            <form method="POST" action="index.php?m=pawpayments_topup">
                <div class="form-group mb-3">
                    <label for="amount">Amount</label>
                    <input type="number" class="form-control" id="amount" name="amount"
                           value="{$amount}" min="1" max="100000" step="0.01" required
                           placeholder="Enter amount">
                </div>
                <div class="form-group mb-3">
                    <label for="currency">Currency</label>
                    <select class="form-control" id="currency" name="currency">
                        {foreach from=$supported_fiats item=fiat}
                            <option value="{$fiat}" {if $currency == $fiat}selected{/if}>{$fiat}</option>
                        {/foreach}
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    Continue to Payment
                </button>
            </form>
        {/if}
    </div>
</div>
