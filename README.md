Feedback on customer billing : 

- this billing redirect should check it-self : http://localhost/billing?email=ilzammulkhaq85%40gmail.com&status=active&subscription_id=sub_0Nn69tGBWPvSOaI67fnyr, meaning given that id it should be somehow can check to dodo, so we not rely so much
on webhook ate the first time

- i think there is should be no 'free' tier at all, all plan have a price, but all can be free-trial, meaning at the first
beginning the customer either during registration or whatsoever is that should pick a plan and type their credit card, 
so we know they want to purchase the product itself


feedback on customer auth : 

- on login page customer add link to register
- when they are register, please add check your email page so this is blocked meaning the customer should verify email first then
can login -> so no need to add button re-send email on dashboard, remove that, that is silly ui
- where is the forgot password, otp etc like that? i did not see it?

feedback on customer workspace : 

- instead on show it on page which is dashboard, just make add new workspace on a modal instead
- i think for now, just make workspace is just a single, meaning 1 workspace 1 customer, later we will ad support for more workspace