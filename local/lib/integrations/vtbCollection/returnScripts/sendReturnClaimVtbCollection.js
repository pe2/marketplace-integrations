function sendReturnClaim(orderId) {
    $.ajax({
        type: "POST",
        url: "/local/lib/integrations/vtbCollection/returnScripts/sendReturnClaimVtbCollection.php",
        data: { orderId: orderId }
    }).done(function (message) {
        alert(message);
    });
}